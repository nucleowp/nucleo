<?php

declare(strict_types=1);

namespace Nucleo\Forms;

defined('ABSPATH') || exit;

use Nucleo\Abilities\RateLimiter;
use Nucleo\Compiler\ContentLocale;
use Nucleo\Compiler\LinkRefs;
use Nucleo\Compiler\LinkTargets;
use Nucleo\Support\ClientIp;

/** POST nucleo/v1/form/<doc>/<node> e GET .../nonce. */
final class FormRest
{
    private const NAMESPACE = 'nucleo/v1';
    private const ROUTE = '/form/(?P<doc>\d+)/(?P<node>[a-z0-9]+)';
    private const SUBMIT_LIMIT = 5;
    private const SUBMIT_WINDOW = 60;
    private const OTP_LIMIT = 3;
    private const OTP_WINDOW = 900;
    /** Verifiche del codice a metà modulo: limite proprio, oltre ai 5 tentativi per ticket. */
    private const VERIFY_LIMIT = 10;
    /** Durata del cookie del ticket a passo: copre ttl massimo (30 minuti) e prova verificata (30 minuti). */
    private const STEP_COOKIE_TTL = 3600;

    /**
     * Invio di un modulo dai visitatori del sito, anche non collegati. Il controllo è nell'handler: nonce HMAC del
     * modulo, honeypot, limite di frequenza per IP e captcha se attivo.
     */
    public static function publicFormEndpoint(): bool
    {
        return true;
    }

    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'publicFormEndpoint'],
        ]);
        register_rest_route(self::NAMESPACE, self::ROUTE . '/nonce', [
            'methods' => 'GET',
            'callback' => [self::class, 'handleNonce'],
            'permission_callback' => [self::class, 'publicFormEndpoint'],
        ]);
    }

    public static function nonceAction(int $doc, string $node): string
    {
        return 'nucleo_form:' . $doc . ':' . $node;
    }

    /**
     * Nonce del modulo indipendente dall'utente: la REST vede ogni invio senza X-WP-Nonce come anonimo,
     * mentre la pagina può essere disegnata per un utente collegato. HMAC di wp_nonce_tick() e dell'azione.
     */
    public static function nonce(int $doc, string $node): string
    {
        return self::hash((int) wp_nonce_tick(), $doc, $node);
    }

    /** Valido per il tick corrente e il precedente (12-24 ore), come wp_verify_nonce. */
    public static function verifyNonce(string $nonce, int $doc, string $node): bool
    {
        $tick = (int) wp_nonce_tick();

        return $nonce !== ''
            && (hash_equals(self::hash($tick, $doc, $node), $nonce) || hash_equals(self::hash($tick - 1, $doc, $node), $nonce));
    }

    private static function hash(int $tick, int $doc, string $node): string
    {
        return substr(wp_hash($tick . '|' . self::nonceAction($doc, $node), 'nonce'), -12, 10);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function handleNonce(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $doc = (int) $request->get_param('doc');
        $node = (string) $request->get_param('node');

        $config = self::form($doc, $node);
        if ($config === null) {
            return self::notFound();
        }

        $response = new \WP_REST_Response(['nonce' => self::nonce($doc, $node)]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /** @return \WP_REST_Response|\WP_Error */
    /** Risposte, errori ed email nella lingua del documento: con un plugin multilingua il modulo sta in una traduzione. */
    public static function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return ContentLocale::runForPost((int) $request->get_param('doc'), static fn (): \WP_REST_Response|\WP_Error => self::process($request));
    }

    private static function process(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $doc = (int) $request->get_param('doc');
        $node = (string) $request->get_param('node');

        $config = self::form($doc, $node);
        if ($config === null) {
            return self::notFound();
        }

        $params = self::params($request, $config);
        $ip = ClientIp::get();

        // Honeypot: il bot riceve un successo, nessuna azione.
        if (($params['_nc_hp'] ?? '') !== '') {
            return self::answer($request, $params, $node, 200, self::done($config));
        }

        if (!self::verifyNonce(self::str($params, '_nc_nonce'), $doc, $node)) {
            return self::answer($request, $params, $node, 403, [
                'ok' => false,
                'code' => 'bad_nonce',
                'message' => $config->labels['error'],
            ]);
        }

        // Verifica del codice a metà modulo (passo con "step").
        $step = $config->otpStep();
        $otp = $config->otp();
        $otpMode = self::str($params, '_nc_otp');
        if (!in_array($otpMode, ['', 'send', 'verify'], true)) {
            return self::answer($request, $params, $node, 400, [
                'ok' => false,
                'code' => 'invalid',
                'message' => $config->labels['error'],
            ]);
        }

        // La verifica del codice a metà modulo ha un contatore suo: send, verify e invio finale non si sommano.
        $verifying = $step !== null && $otpMode === 'verify';
        $limitKey = ($verifying ? 'form-verify|' : 'form|') . $doc . '|' . $node . '|' . $ip;
        if (!RateLimiter::hit($limitKey, $verifying ? self::VERIFY_LIMIT : self::SUBMIT_LIMIT, self::SUBMIT_WINDOW)) {
            return self::answer($request, $params, $node, 429, [
                'ok' => false,
                'code' => 'rate_limited',
                'message' => $config->labels['error'],
            ]);
        }

        $context = self::publicPost(is_numeric($params['_nc_ctx'] ?? null) ? (int) $params['_nc_ctx'] : 0);

        // Captcha su ogni richiesta, tranne i passi che il server verifica su un ticket emesso dopo un captcha:
        // la verifica del passo, il ticket del codice classico e la prova del passo verificato.
        // L'esenzione dipende dalla configurazione del modulo, non dai campi di controllo inviati.
        $ticket = self::str($params, '_nc_ticket');
        $proof = self::str($params, '_nc_proof');
        $exempt = ($step !== null && $otpMode === 'verify')
            || ($otp !== null && $otpMode === '' && $ticket !== '' && $proof === '')
            || ($step !== null && $otp !== null && $otpMode === '' && $proof !== '');
        if ($config->captcha && !$exempt && !Captcha::verify($params, $ip)) {
            return self::answer($request, $params, $node, 403, [
                'ok' => false,
                'code' => 'captcha',
                'message' => $config->labels['captcha'],
            ]);
        }
        if ($step !== null && $otp !== null && $otpMode === 'send') {
            return self::sendStep($request, $params, $config, $otp, $doc, $node, $context);
        }
        if ($step !== null && $otpMode === 'verify') {
            return self::verifyStep($request, $params, $config, $doc, $node);
        }

        // Con la prova di un passo verificato vale l'invio finale, non il codice classico.
        if ($ticket !== '' && $proof === '') {
            return self::fromTicket($request, $params, $config, $doc, $node, $ticket, $ip);
        }

        $checked = FormValidator::validate($config, $params);
        if ($checked['errors'] !== []) {
            return self::answer($request, $params, $node, 422, [
                'ok' => false,
                'code' => 'invalid',
                'errors' => $checked['errors'],
                'message' => $config->labels['error'],
            ]);
        }

        $values = $checked['values'];
        foreach ($config->fields as $field) {
            if ($field['type'] === 'context') {
                $values[$field['name']] = self::contextValue($context, (string) $field['from']);
            }
        }
        $submission = new FormSubmission($doc, $config, $values, $context, $ip);

        if ($otp !== null) {
            $field = is_string($otp['field'] ?? null) ? $otp['field'] : '';
            $address = is_string($values[$field] ?? null) ? $values[$field] : '';

            // Invio finale dopo la verifica del passo: la prova vale una volta sola e per questo indirizzo.
            if ($step !== null && $proof !== '') {
                if (!OtpTickets::redeem($doc, $node, $proof, $address, self::stepSecret($doc, $node))) {
                    return self::answer($request, $params, $node, 410, [
                        'ok' => false,
                        'code' => 'otp_expired',
                        'message' => $config->labels['expired'],
                    ]);
                }

                return self::finish($request, $params, $submission);
            }

            if (!RateLimiter::hit('otp|' . self::otpAddress($address), self::OTP_LIMIT, self::OTP_WINDOW)) {
                return self::answer($request, $params, $node, 429, [
                    'ok' => false,
                    'code' => 'rate_limited',
                    'message' => $config->labels['error'],
                ]);
            }
            $ticket = OtpTickets::issue($submission, $otp);
            if ($ticket === null) {
                return self::answer($request, $params, $node, 500, [
                    'ok' => false,
                    'code' => 'otp_failed',
                    'message' => $config->labels['otpFailed'],
                ]);
            }

            return self::answer($request, $params, $node, 200, [
                'ok' => true,
                'status' => 'otp',
                'ticket' => $ticket,
                'message' => $config->labels['sent'],
            ]);
        }

        return self::finish($request, $params, $submission);
    }

    /**
     * Primo passo OTP: valida i campi dei passi fino a quello del codice (email, consenso…), poi limite per indirizzo
     * e codice per email. Il segreto del ticket va in un cookie HttpOnly: senza, il ticket non vale.
     * @param array<string,mixed> $params
     * @param array<string,mixed> $otp
     */
    private static function sendStep(
        \WP_REST_Request $request,
        array $params,
        FormConfig $config,
        array $otp,
        int $doc,
        string $node,
        ?\WP_Post $context,
    ): \WP_REST_Response {
        $field = is_string($otp['field'] ?? null) ? $otp['field'] : '';
        $last = max(0, count($config->steps) - 1);
        $upTo = (int) $config->otpStep();
        $before = array_values(array_filter(
            $config->fields,
            static fn (array $f): bool => $f['type'] !== 'context' && min((int) $f['step'], $last) <= $upTo,
        ));
        $checked = FormValidator::validate(new FormConfig($node, $before, [], [], $config->labels), $params);
        if ($checked['errors'] !== []) {
            return self::answer($request, $params, $node, 422, [
                'ok' => false,
                'code' => 'invalid',
                'errors' => $checked['errors'],
                'message' => $config->labels['error'],
            ]);
        }

        $address = is_string($checked['values'][$field] ?? null) ? $checked['values'][$field] : '';
        if (!RateLimiter::hit('otp|' . self::otpAddress($address), self::OTP_LIMIT, self::OTP_WINDOW)) {
            return self::answer($request, $params, $node, 429, [
                'ok' => false,
                'code' => 'rate_limited',
                'message' => $config->labels['error'],
            ]);
        }

        $secret = wp_generate_password(32, false);
        $ticket = OtpTickets::issueForAddress($doc, $node, $address, $otp, $context, $secret);
        if ($ticket === null) {
            return self::answer($request, $params, $node, 500, [
                'ok' => false,
                'code' => 'otp_failed',
                'message' => $config->labels['otpFailed'],
            ]);
        }

        $response = self::answer($request, $params, $node, 200, [
            'ok' => true,
            'status' => 'code',
            'ticket' => $ticket,
            'message' => $config->labels['sent'],
        ]);
        $response->header('Set-Cookie', sprintf(
            '%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Strict%s',
            self::stepCookie($doc, $node),
            $secret,
            self::STEP_COOKIE_TTL,
            is_ssl() ? '; Secure' : '',
        ));

        return $response;
    }

    /** Nome del cookie del ticket a passo, uno per modulo. */
    public static function stepCookie(int $doc, string $node): string
    {
        return 'nucleo_otp_' . $doc . '_' . $node;
    }

    /** Segreto del cookie del ticket a passo inviato dal browser; '' se manca. */
    private static function stepSecret(int $doc, string $node): string
    {
        $value = sanitize_text_field(wp_unslash((string) ($_COOKIE[self::stepCookie($doc, $node)] ?? '')));

        return is_string($value) && preg_match('/\A[A-Za-z0-9]{32}\z/', $value) === 1 ? $value : '';
    }

    /**
     * Verifica del codice di un passo: ticket "step" verificato, che poi serve all'invio finale.
     * @param array<string,mixed> $params
     */
    private static function verifyStep(
        \WP_REST_Request $request,
        array $params,
        FormConfig $config,
        int $doc,
        string $node,
    ): \WP_REST_Response {
        $result = OtpTickets::confirm(
            $doc,
            $node,
            self::str($params, '_nc_ticket'),
            self::str($params, '_nc_code'),
            self::stepSecret($doc, $node),
        );
        if ($result === 'expired') {
            return self::answer($request, $params, $node, 410, [
                'ok' => false,
                'code' => 'otp_expired',
                'message' => $config->labels['expired'],
            ]);
        }
        if ($result === 'wrong') {
            return self::answer($request, $params, $node, 422, [
                'ok' => false,
                'code' => 'otp_wrong',
                'message' => $config->labels['error'],
            ]);
        }

        return self::answer($request, $params, $node, 200, ['ok' => true, 'status' => 'verified']);
    }

    /**
     * Secondo invio OTP: codice verificato, poi valori e contesto salvati nel ticket.
     * @param array<string,mixed> $params
     */
    private static function fromTicket(
        \WP_REST_Request $request,
        array $params,
        FormConfig $config,
        int $doc,
        string $node,
        string $ticket,
        string $ip,
    ): \WP_REST_Response {
        $result = OtpTickets::verify($doc, $node, $ticket, self::str($params, '_nc_code'));
        if ($result === 'expired') {
            return self::answer($request, $params, $node, 410, [
                'ok' => false,
                'code' => 'otp_expired',
                'message' => $config->labels['error'],
            ]);
        }
        if ($result === 'wrong') {
            return self::answer($request, $params, $node, 422, [
                'ok' => false,
                'code' => 'otp_wrong',
                'message' => $config->labels['error'],
            ]);
        }

        $context = self::publicPost($result['context']);
        $submission = new FormSubmission($doc, $config, $result['values'], $context, $ip);

        return self::finish($request, $params, $submission);
    }

    /** @param array<string,mixed> $params */
    private static function finish(\WP_REST_Request $request, array $params, FormSubmission $submission): \WP_REST_Response
    {
        $result = FormActions::run($submission);
        if (!$result['ok']) {
            $body = [
                'ok' => false,
                'code' => 'action_failed',
                'message' => $result['message'],
            ];
            $detail = $result['detail'] ?? '';
            if ($detail !== '' && self::canSeeDetail()) {
                $body['detail'] = $detail;
            }

            return self::answer($request, $params, $submission->config->node, 500, $body);
        }

        return self::answer($request, $params, $submission->config->node, 200, self::done($submission->config));
    }

    /**
     * Il dettaglio dell'errore va solo a chi amministra il sito o sul server di sviluppo.
     * Il filtro nucleo/forms/error_detail permette di cambiare la scelta (per esempio nei test).
     */
    private static function canSeeDetail(): bool
    {
        $allowed = current_user_can('manage_options') || in_array(wp_get_environment_type(), ['local', 'development'], true);

        return (bool) apply_filters('nucleo/forms/error_detail', $allowed);
    }

    /** @return array<string,mixed> */
    private static function done(FormConfig $config): array
    {
        $body = ['ok' => true, 'status' => 'done', 'message' => $config->labels['success']];
        $url = $config->redirect === '' ? null : self::redirectUrl($config->redirect);
        if ($url !== null) {
            $body['redirect'] = $url;
        }

        return $body;
    }

    /**
     * Destinazione dopo l'invio: riferimento interno (home, page:<slug>, ...) risolto come i link della pagina,
     * oppure URL. Null se il riferimento non ha un contenuto pubblicato o l'URL non è utilizzabile.
     */
    private static function redirectUrl(string $ref): ?string
    {
        $link = LinkRefs::parse($ref);
        if ($link !== null) {
            $found = LinkTargets::lookup($link);
            if ($found === null) {
                return null;
            }

            return $found['url'] . ($link['fragment'] !== '' ? '#' . $link['fragment'] : '');
        }
        // esc_url_raw scarta i percorsi non sicuri (javascript:, ecc.) restituendo ''.
        $url = esc_url_raw($ref);

        return $url === '' ? null : $url;
    }

    /**
     * Risposta JSON, oppure con l'header X-Nucleo-Form: 1 assente un redirect 303 a _nc_back con l'esito in query.
     * @param array<string,mixed> $params
     * @param array<string,mixed> $body
     */
    private static function answer(\WP_REST_Request $request, array $params, string $node, int $status, array $body): \WP_REST_Response
    {
        if ($request->get_header('X-Nucleo-Form') === '1') {
            return new \WP_REST_Response($body, $status);
        }

        $ncStatus = ($body['ok'] ?? false) === true ? (string) ($body['status'] ?? 'done') : 'error';
        $query = ['nc_form' => $node, 'nc_status' => $ncStatus];
        if ($ncStatus === 'otp') {
            $query['nc_ticket'] = (string) ($body['ticket'] ?? '');
        }
        $back = wp_validate_redirect(self::str($params, '_nc_back'), home_url('/'));

        $response = new \WP_REST_Response(null, 303);
        $response->header('Location', add_query_arg($query, $back));

        return $response;
    }

    /** Configurazione del nc/native-form nel documento pubblicato e senza password; null se non c'è. */
    private static function form(int $doc, string $node): ?FormConfig
    {
        $post = $doc > 0 ? get_post($doc) : null;
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish' || $post->post_password !== '') {
            return null;
        }

        $data = self::slotData($post->ID, $node);
        if ($data === null) {
            return null;
        }

        $raw = $data['config'] ?? null;

        return is_array($raw) ? FormConfig::fromArray($raw) : null;
    }

    /**
     * Dati dello slot nc/native-form con il nodo indicato nel meta _nucleo_rt del documento; null se assente.
     * @return array<string,mixed>|null
     */
    private static function slotData(int $doc, string $node): ?array
    {
        $rt = get_post_meta($doc, '_nucleo_rt', true);
        if (!is_array($rt) || !is_array($rt['slots'] ?? null)) {
            return null;
        }
        foreach ($rt['slots'] as $slot) {
            if (!is_array($slot) || ($slot['block'] ?? null) !== 'nc/native-form') {
                continue;
            }
            $data = $slot['data'] ?? null;
            if (is_array($data) && ($data['node'] ?? null) === $node) {
                return $data;
            }
        }

        return null;
    }

    /** Post di contesto pubblicato, o null. */
    private static function publicPost(int $id): ?\WP_Post
    {
        $post = $id > 0 ? get_post($id) : null;

        return $post instanceof \WP_Post && $post->post_status === 'publish' ? $post : null;
    }

    /** Valore di un campo context: id, titolo o link del post di contesto; vuoto senza contesto. */
    private static function contextValue(?\WP_Post $context, string $from): string
    {
        if ($context === null) {
            return '';
        }

        return match ($from) {
            'post.id' => (string) $context->ID,
            'post.title' => (string) $context->post_title,
            'post.url' => (string) get_permalink($context),
            default => '',
        };
    }

    /** Indirizzo per il limite OTP: minuscole, senza spazi e senza +tag nella parte locale (a+x@B.it = a@b.it). */
    private static function otpAddress(string $address): string
    {
        $address = strtolower(trim($address));
        $at = strrpos($address, '@');
        if ($at === false) {
            return $address;
        }
        $local = substr($address, 0, $at);
        $plus = strpos($local, '+');
        if ($plus !== false) {
            $local = substr($local, 0, $plus);
        }

        return $local . substr($address, $at);
    }

    /** @param array<string,mixed> $params */
    /** Chiavi di controllo del modulo, testo semplice; _nc_back è l'indirizzo della pagina, quindi un URL. */
    private const CONTROL_KEYS = ['_nc_hp', '_nc_nonce', '_nc_otp', '_nc_ctx', '_nc_ticket', '_nc_proof', '_nc_code'];
    private const CAPTCHA_KEYS = ['g-recaptcha-response', 'h-captcha-response', 'cf-turnstile-response', 'frc-captcha-response'];

    /**
     * Legge dal corpo della richiesta solo le chiavi note: quelle di controllo e del captcha, sanificate qui, e i
     * campi dichiarati dal modulo, che FormValidator sanifica e valida per tipo.
     * @return array<string,mixed>
     */
    private static function params(\WP_REST_Request $request, FormConfig $config): array
    {
        $body = $request->get_body_params();
        $params = [];
        foreach (array_merge(self::CONTROL_KEYS, self::CAPTCHA_KEYS) as $key) {
            if (is_string($body[$key] ?? null)) {
                $params[$key] = sanitize_text_field(wp_unslash($body[$key]));
            }
        }
        if (is_string($body['_nc_back'] ?? null)) {
            $params['_nc_back'] = sanitize_url(wp_unslash($body['_nc_back']));
        }
        foreach ($config->fields as $field) {
            $name = (string) $field['name'];
            if (array_key_exists($name, $body)) {
                $params[$name] = wp_unslash($body[$name]);
            }
        }

        return $params;
    }

    /** @param array<string,mixed> $params */
    private static function str(array $params, string $key): string
    {
        return is_string($params[$key] ?? null) ? $params[$key] : '';
    }

    private static function notFound(): \WP_Error
    {
        return new \WP_Error('nucleo_form_not_found', 'Not found', ['status' => 404]);
    }
}
