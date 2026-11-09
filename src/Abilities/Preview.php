<?php

declare(strict_types=1);

namespace Nucleo\Abilities;

use Nucleo\Compiler\ContentLocale;
use Nucleo\Runtime\SlotRenderer;
use Nucleo\Runtime\Slots;
use Nucleo\Schema\ValidationError;
use Nucleo\Services;

/** Compila un documento senza salvarlo (usata da nucleo/doc-preview) e serve l'anteprima firmata. */
final class Preview
{
    private const QUERY_VAR = 'nucleo_preview';
    private const TRANSIENT_PREFIX = 'nucleo_preview_';

    /**
     * Normalizza, valida e compila. Non scrive nulla.
     * @param array<string,mixed> $doc
     * @param bool $markIds true per l'anteprima dell'editor (data-nc-id sui nodi)
     * @param int $postId post del documento: binding, link salvati e lingua come nel salvataggio; 0 senza contesto
     * @param int $contextPostId post su cui riempire gli slot dinamici (es. un prodotto per un layout); 0: slot vuoti
     * @return array{ok:true,html:string,css:string,errors?:list<array<string,mixed>>}|array{ok:false,error:array<string,mixed>}
     */
    public static function render(array $doc, bool $markIds = false, int $postId = 0, int $contextPostId = 0): array
    {
        $expansion = Services::documents()->prepare($doc, $postId);
        $doc = $expansion->doc;

        $errors = Services::documents()->check($expansion);
        // Anteprima dell'editor: i nodi non validi diventano segnaposto e il resto della pagina resta visibile.
        $invalid = $markIds && $errors !== [] ? self::invalidNodes($doc, $errors) : null;
        $bindings = $postId > 0 ? Services::documents()->bindingsFor($postId, $doc) : null;
        $locale = ContentLocale::forPost($postId);
        if ($invalid !== null) {
            try {
                $compiled = ContentLocale::run($locale, static fn () => Services::compiler()->compile($doc, $bindings, true, $invalid));

                return ['ok' => true, 'html' => $compiled->html, 'css' => $compiled->css, 'errors' => array_map(static fn (ValidationError $e): array => $e->toArray(), $errors)];
            } catch (\Throwable) {
                $invalid = null;
            }
        }
        if ($errors !== []) {
            return [
                'ok' => false,
                'error' => [
                    'code' => 'invalid_document',
                    'message' => __('Invalid document.', 'nucleo'),
                    'errors' => array_map(static fn (ValidationError $e): array => $e->toArray(), $errors),
                ],
            ];
        }

        // Come la pagina salvata: etichette nella lingua del sito, non in quella di chi modifica.
        $compiled = ContentLocale::run($locale, static fn () => Services::compiler()->compile($doc, $bindings, $markIds));
        $html = $compiled->html;
        if ($contextPostId > 0 && $compiled->slots !== []) {
            $html = self::onPost($contextPostId, static fn (): string => (new SlotRenderer())->fill(
                $compiled->html,
                $compiled->slots,
                static fn (string $block, array $data): string => Slots::render($block, $data),
            ));
        }

        return ['ok' => true, 'html' => $html, 'css' => $compiled->css];
    }

    /**
     * Esegue $fill con la query principale sul post, come nella sua pagina, poi ripristina query e post globali.
     * @param callable():string $fill
     */
    private static function onPost(int $postId, callable $fill): string
    {
        global $wp_query, $wp_the_query, $post;
        $saved = [$wp_query, $wp_the_query, $post];
        $type = get_post_type($postId);
        $query = new \WP_Query(['p' => $postId, 'post_type' => is_string($type) ? $type : 'any', 'post_status' => 'any']);
        $wp_query = $query;
        $wp_the_query = $query;
        try {
            if ($query->have_posts()) {
                $query->the_post();
            }

            return $fill();
        } finally {
            [$wp_query, $wp_the_query, $post] = $saved;
            wp_reset_postdata();
        }
    }

    /**
     * Nodi a cui appartengono gli errori (id => primo messaggio), dal percorso /root/children/N/....
     * Null se un errore non riguarda un nodo (es. campi del documento): allora l'anteprima non si compila.
     * @param array<string,mixed> $doc
     * @param list<ValidationError> $errors
     * @return array<string,string>|null
     */
    private static function invalidNodes(array $doc, array $errors): ?array
    {
        $out = [];
        foreach ($errors as $error) {
            $parts = explode('/', trim($error->path, '/'));
            if (($parts[0] ?? '') !== 'root') {
                return null;
            }
            $node = $doc['root'] ?? null;
            for ($i = 1; isset($parts[$i + 1]) && $parts[$i] === 'children' && ctype_digit($parts[$i + 1]); $i += 2) {
                $node = is_array($node) ? ($node['children'][(int) $parts[$i + 1]] ?? null) : null;
            }
            $id = is_array($node) ? ($node['id'] ?? null) : null;
            if (!is_string($id) || $id === '' || ($doc['root']['id'] ?? null) === $id) {
                return null;
            }
            $out[$id] ??= $error->message;
        }

        return $out;
    }

    /**
     * Token firmato: postId.userId.scadenza.nonce.firma (HMAC-SHA256 con il segreto), codificato in base64url.
     * postId 0 indica un'anteprima senza post, salvata in transient. Funzione pura: il segreto è un parametro.
     */
    public static function token(int $postId, int $userId, int $expires, string $nonce, string $secret): string
    {
        $payload = "$postId.$userId.$expires.$nonce";
        $mac = hash_hmac('sha256', $payload, $secret);

        return self::base64UrlEncode("$payload.$mac");
    }

    /**
     * Dati del token (postId, userId, nonce) se autentico e non scaduto a $now, altrimenti null.
     * @return array{postId:int,userId:int,nonce:string}|null
     */
    public static function verify(string $token, string $secret, int $now): ?array
    {
        $decoded = self::base64UrlDecode($token);
        if ($decoded === null) {
            return null;
        }

        $parts = explode('.', $decoded);
        if (count($parts) !== 5 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_digit($parts[2]) || !ctype_xdigit($parts[3])) {
            return null;
        }
        $postId = (int) $parts[0];
        $userId = (int) $parts[1];
        $expires = (int) $parts[2];
        $nonce = $parts[3];
        if ($userId < 1 || $expires < $now) {
            return null;
        }

        // Confronto sul token ricodificato: qualunque byte in più o in meno lo invalida.
        if (!hash_equals(self::token($postId, $userId, $expires, $nonce, $secret), $token)) {
            return null;
        }

        return ['postId' => $postId, 'userId' => $userId, 'nonce' => $nonce];
    }

    /** Token per l'utente corrente, valido $ttl secondi. Il nonce casuale rende unica ogni anteprima. */
    public static function issue(int $postId, int $ttl): string
    {
        return self::token($postId, get_current_user_id(), time() + $ttl, bin2hex(random_bytes(16)), self::secret());
    }

    /** URL di anteprima con il token in query: il permalink del post, o la home se non c'è post (postId 0). */
    public static function signedUrl(string $token, int $postId): string
    {
        $permalink = $postId > 0 ? get_permalink($postId) : false;
        $base = is_string($permalink) && $permalink !== '' ? $permalink : home_url('/');

        return add_query_arg(self::QUERY_VAR, $token, $base);
    }

    /** Salva html e css di un'anteprima senza post, legata al token, per $ttl secondi. */
    public static function store(string $token, string $html, string $css, int $ttl): void
    {
        set_transient(self::transientKey($token), ['html' => $html, 'css' => $css], $ttl);
    }

    /** Aggancia la gestione dell'anteprima firmata al frontend. */
    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'serve']);
    }

    /**
     * Con un token valido mostra la versione corrente del documento, anche bozza, dentro il tema.
     * Il token porta l'utente richiedente: senza il permesso di modifica risponde 403.
     * Con un token non valido o scaduto risponde 403.
     */
    public static function serve(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- link di anteprima con token firmato, verificato subito dopo.
        $raw = isset($_GET[self::QUERY_VAR]) && is_string($_GET[self::QUERY_VAR]) ? sanitize_text_field(wp_unslash($_GET[self::QUERY_VAR])) : null;
        if ($raw === null) {
            return;
        }

        header('X-Robots-Tag: noindex');
        header('Cache-Control: no-store');

        $claims = self::verify($raw, self::secret(), time());
        if ($claims === null) {
            wp_die(esc_html__('Preview is invalid or has expired.', 'nucleo'), esc_html__('Preview', 'nucleo'), ['response' => 403]);
        }

        if ($claims['postId'] > 0) {
            if (!user_can($claims['userId'], 'edit_post', $claims['postId'])) {
                wp_die(esc_html__('Preview is invalid or has expired.', 'nucleo'), esc_html__('Preview', 'nucleo'), ['response' => 403]);
            }

            $found = Services::documents()->get($claims['postId']);
            $document = $found['document'] ?? null;
            if (!is_array($document)) {
                wp_die(esc_html__('Document not found.', 'nucleo'), esc_html__('Preview', 'nucleo'), ['response' => 404]);
            }

            $compiled = self::render($document, false, $claims['postId']);
            if ($compiled['ok'] !== true) {
                wp_die(esc_html__('Document cannot be compiled.', 'nucleo'), esc_html__('Preview', 'nucleo'), ['response' => 422]);
            }
            $html = $compiled['html'];
            $css = $compiled['css'];
        } else {
            if (!user_can($claims['userId'], 'edit_posts')) {
                wp_die(esc_html__('Preview is invalid or has expired.', 'nucleo'), esc_html__('Preview', 'nucleo'), ['response' => 403]);
            }

            $stored = get_transient(self::transientKey($raw));
            if (!is_array($stored) || !is_string($stored['html'] ?? null) || !is_string($stored['css'] ?? null)) {
                wp_die(esc_html__('Preview not found.', 'nucleo'), esc_html__('Preview', 'nucleo'), ['response' => 404]);
            }
            $html = $stored['html'];
            $css = $stored['css'];
        }

        status_header(200);
        // Il CSS compilato entra come stile inline di un handle senza file, stampato da wp_head; "</" non chiude il tag.
        wp_register_style(self::STYLE_HANDLE, false, [], NUCLEO_VERSION);
        wp_enqueue_style(self::STYLE_HANDLE);
        wp_add_inline_style(self::STYLE_HANDLE, str_replace('</', '<\/', $css));
        get_header();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML e CSS del compilatore, che escapa ogni valore nei renderer.
        echo $html;
        get_footer();
        exit;
    }

    private const STYLE_HANDLE = 'nucleo-preview';

    private static function secret(): string
    {
        return wp_salt('nonce');
    }

    private static function transientKey(string $token): string
    {
        return self::TRANSIENT_PREFIX . hash('sha256', $token);
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $token): ?string
    {
        $b64 = strtr($token, '-_', '+/');
        $padded = str_pad($b64, strlen($b64) + (4 - strlen($b64) % 4) % 4, '=');
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
