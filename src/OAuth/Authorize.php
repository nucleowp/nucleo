<?php

declare(strict_types=1);

namespace Nucleo\OAuth;

use Nucleo\Admin\Icons;

/**
 * Schermata di consenso OAuth in wp-admin (pagina nascosta). Il consenso è sempre richiesto:
 * ogni richiesta viene validata di nuovo, sia in GET sia in POST, e nulla viene ricordato.
 * Errori prima della verifica del redirect_uri: pagina di errore. Dopo: redirect con error e state.
 */
final class Authorize
{
    public const SLUG = 'nucleo-authorize';
    public const NONCE = 'nucleo_authorize';

    private const STATE_MAX = 512;

    /** @var array<string,mixed>|null richiesta validata nella fase GET, letta da render() */
    private static ?array $request = null;

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addPage']);
        add_action('current_screen', [self::class, 'setTitle']);
    }

    /** Pagina fuori dal menu: WordPress non ne trova il titolo e admin-header passerebbe null a strip_tags. */
    public static function setTitle(): void
    {
        if (($GLOBALS['plugin_page'] ?? null) === self::SLUG) {
            $GLOBALS['title'] = __('Authorize AI agent', 'nucleo');
        }
    }

    public static function addPage(): void
    {
        $hook = add_submenu_page(
            '',
            __('Authorize AI agent', 'nucleo'),
            __('Authorize AI agent', 'nucleo'),
            'read',
            self::SLUG,
            [self::class, 'render']
        );
        if (is_string($hook)) {
            // Il load-hook precede l'output dell'admin, quindi wp_redirect funziona.
            add_action('load-' . $hook, [self::class, 'handle']);
        }
    }

    /** Gestisce GET (validazione e mostra schermata) e POST (decisione). */
    public static function handle(): void
    {
        // Pagina di errore, senza redirect: il client non riceve nulla su un canale in chiaro.
        if (Server::rejectsPlainHttp(is_ssl(), wp_get_environment_type())) {
            wp_die(
                esc_html__('HTTPS is required for OAuth requests. Behind a reverse proxy, check that it forwards X-Forwarded-Proto: https.', 'nucleo'),
                esc_html__('Invalid authorization', 'nucleo'),
                ['response' => 400]
            );
        }

        $isPost = sanitize_text_field(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) === 'POST';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- OAuth: la GET è la richiesta del client; la POST verifica il nonce in decide() prima di approvare o negare.
        $input = wp_unslash($isPost ? $_POST : $_GET);
        if (!is_array($input)) {
            $input = [];
        }

        if ($isPost) {
            try {
                $url = ($input['decision'] ?? '') === 'deny'
                    ? self::deny($input, get_current_user_id())
                    : self::approve($input, get_current_user_id());
            } catch (\RuntimeException $e) {
                wp_die(esc_html($e->getMessage()), esc_html__('Invalid authorization', 'nucleo'), ['response' => 400]);
            }
            // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirect_uri del client OAuth, già confrontato con quelli registrati (UrlGuard::isAllowedRedirect).
            wp_redirect($url);
            exit;
        }

        $request = self::validateRequest($input);
        if ($request instanceof \WP_Error) {
            $target = self::targetOf($request->get_error_data());
            if ($target !== null) {
                // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirect_uri del client OAuth, già confrontato con quelli registrati (UrlGuard::isAllowedRedirect).
                wp_redirect(self::errorUrl($target['redirect_uri'], $target['state'], self::codeOf($request)));
                exit;
            }
            wp_die(esc_html($request->get_error_message()), esc_html__('Invalid authorization', 'nucleo'), ['response' => 400]);
        }
        self::$request = $request;
    }

    /**
     * Valida i parametri della richiesta. Gli errori con redirect_uri nei dati sono rimandabili al client;
     * quelli senza vanno mostrati in una pagina di errore.
     *
     * @param array<string,mixed> $q
     * @return array{client_id:string,client_name:string,redirect_uri:string,state:string,scopes:list<string>,code_challenge:string,resource:string}|\WP_Error
     */
    public static function validateRequest(array $q): array|\WP_Error
    {
        $clientId = self::str($q, 'client_id');
        $client = $clientId === '' ? null : (new ClientFetcher())->resolve($clientId);
        if ($client === null) {
            return new \WP_Error('invalid_client', __('Unknown client or invalid metadata document.', 'nucleo'));
        }
        if (!Server::clientAllowed($client['kind'], $clientId, $client['redirect_uris'], Server::allowedDomains())) {
            return new \WP_Error('invalid_client', __('The client is not in the allowed domains list.', 'nucleo'));
        }

        $redirectUri = self::str($q, 'redirect_uri');
        if (!(new UrlGuard())->isAllowedRedirect($redirectUri, $client['redirect_uris'])) {
            return new \WP_Error('invalid_redirect_uri', __('redirect_uri is not registered for this client.', 'nucleo'));
        }

        // Da qui in poi il redirect_uri è verificato: gli errori tornano al client.
        $state = self::str($q, 'state');
        $target = ['redirect_uri' => $redirectUri, 'state' => $state];

        if (self::str($q, 'response_type') !== 'code') {
            return self::redirectError('unsupported_response_type', __('response_type must be code.', 'nucleo'), $target);
        }
        if (strlen($state) > self::STATE_MAX) {
            return self::redirectError('invalid_request', __('state is too long.', 'nucleo'), $target);
        }

        $challenge = self::str($q, 'code_challenge');
        if (preg_match('/\A[A-Za-z0-9\-_]{43}\z/', $challenge) !== 1) {
            return self::redirectError('invalid_request', __('code_challenge is missing or invalid.', 'nucleo'), $target);
        }
        if (self::str($q, 'code_challenge_method') !== 'S256') {
            return self::redirectError('invalid_request', __('code_challenge_method must be S256.', 'nucleo'), $target);
        }

        $raw = preg_split('/\s+/', trim(self::str($q, 'scope')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $scopes = Scopes::parse(implode(' ', $raw));
        if ($scopes === [] || count(array_unique($raw)) !== count($scopes)) {
            return self::redirectError('invalid_scope', __('Scopes are missing or invalid.', 'nucleo'), $target);
        }

        $resource = self::str($q, 'resource');
        if ($resource !== self::mcpUrl()) {
            return self::redirectError('invalid_target', __('resource differs from the MCP server of this site.', 'nucleo'), $target);
        }

        return [
            'client_id' => $clientId,
            'client_name' => $client['client_name'],
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scopes' => $scopes,
            'code_challenge' => $challenge,
            'resource' => $resource,
        ];
    }

    /**
     * Concede gli scope selezionati (campo grant[]) e restituisce l'URL di redirect con code e state.
     * Lancia \RuntimeException se la richiesta non è valida e non si può rimandare al client.
     *
     * @param array<string,mixed> $post
     */
    public static function approve(array $post, int $userId): string
    {
        return self::decide($post, $userId, true);
    }

    /**
     * Nega la richiesta: redirect con error=access_denied.
     *
     * @param array<string,mixed> $post
     */
    public static function deny(array $post, int $userId): string
    {
        return self::decide($post, $userId, false);
    }

    /** @param array<string,mixed> $post */
    private static function decide(array $post, int $userId, bool $grant): string
    {
        if (!self::nonceValid($post)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \RuntimeException(__('Invalid request: nonce expired or invalid.', 'nucleo'));
        }

        $request = self::validateRequest($post);
        if ($request instanceof \WP_Error) {
            $target = self::targetOf($request->get_error_data());
            if ($target === null) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new \RuntimeException($request->get_error_message());
            }

            return self::errorUrl($target['redirect_uri'], $target['state'], self::codeOf($request));
        }

        if (!$grant) {
            return self::errorUrl($request['redirect_uri'], $request['state'], 'access_denied');
        }

        $selected = array_values(array_intersect($request['scopes'], self::stringList($post, 'grant')));
        if ($selected === []) {
            return self::errorUrl($request['redirect_uri'], $request['state'], 'access_denied');
        }
        foreach ($selected as $scope) {
            if (!user_can($userId, Scopes::capability($scope))) {
                return self::errorUrl($request['redirect_uri'], $request['state'], 'invalid_scope');
            }
        }

        try {
            $code = (new Server())->issueCode(
                $request['client_id'],
                $userId,
                $selected,
                $request['code_challenge'],
                $request['redirect_uri'],
                $request['resource']
            );
        } catch (\InvalidArgumentException) {
            return self::errorUrl($request['redirect_uri'], $request['state'], 'server_error');
        }

        return self::redirectWith($request['redirect_uri'], $request['state'], ['code' => $code]);
    }

    /** @param array<string,mixed> $q */
    private static function nonceValid(array $q): bool
    {
        $nonce = self::str($q, '_wpnonce');

        return $nonce !== '' && wp_verify_nonce($nonce, self::NONCE) !== false;
    }

    /** Codice OAuth dell'errore: tutti i codici sono stringhe, il fallback vale solo per tipi inattesi. */
    private static function codeOf(\WP_Error $error): string
    {
        $code = $error->get_error_code();

        return is_string($code) ? $code : 'server_error';
    }

    /** URL di errore verso il client, con state se presente. */
    private static function errorUrl(string $redirectUri, string $state, string $error): string
    {
        return self::redirectWith($redirectUri, $state, ['error' => $error]);
    }

    /**
     * @param array<string,string> $params
     */
    private static function redirectWith(string $redirectUri, string $state, array $params): string
    {
        if ($state !== '') {
            $params['state'] = $state;
        }

        return add_query_arg($params, $redirectUri);
    }

    /**
     * Estrae redirect_uri e state dai dati di un WP_Error, solo se entrambi sono stringhe.
     *
     * @return array{redirect_uri:string,state:string}|null
     */
    private static function targetOf(mixed $data): ?array
    {
        if (!is_array($data) || !is_string($data['redirect_uri'] ?? null) || !is_string($data['state'] ?? null)) {
            return null;
        }

        return ['redirect_uri' => $data['redirect_uri'], 'state' => $data['state']];
    }

    /**
     * @param array{redirect_uri:string,state:string} $target
     */
    private static function redirectError(string $code, string $message, array $target): \WP_Error
    {
        return new \WP_Error($code, $message, $target);
    }

    private static function mcpUrl(): string
    {
        return rest_url('nucleo/v1/mcp');
    }

    /** @param array<string,mixed> $q */
    private static function str(array $q, string $key): string
    {
        $value = $q[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string,mixed> $q
     * @return list<string>
     */
    private static function stringList(array $q, string $key): array
    {
        $value = $q[$key] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** Descrizione in linguaggio semplice di uno scope nella schermata di consenso. */
    private static function scopeLabel(string $scope): string
    {
        return match ($scope) {
            Scopes::READ => __('Read documents, layouts and site status', 'nucleo'),
            Scopes::WRITE => __('Create and edit documents and media', 'nucleo'),
            Scopes::PUBLISH => __('Publish documents', 'nucleo'),
            Scopes::DESIGN => __('Edit design, tokens, layouts and asset rules', 'nucleo'),
            Scopes::ADMIN => __('Manage the cache, status and settings', 'nucleo'),
            default => $scope,
        };
    }

    /** Schermata di consenso. Riceve la richiesta già validata da handle(). */
    public static function render(): void
    {
        $request = self::$request;
        if ($request === null) {
            wp_die(esc_html__('Invalid request.', 'nucleo'));
        }

        $user = wp_get_current_user();
        $roleNames = $user->roles;
        $names = wp_roles()->get_names();
        $roleLabels = implode(', ', array_map(
            static fn (string $role): string => translate_user_role($names[$role] ?? $role),
            $roleNames
        ));
        $clientName = $request['client_name'] !== '' ? $request['client_name'] : $request['client_id'];
        $clientHost = (string) wp_parse_url($request['client_id'], PHP_URL_HOST);
        $domain = $clientHost !== '' ? $clientHost : $clientName;

        $hidden = [
            'response_type' => 'code',
            'client_id' => $request['client_id'],
            'redirect_uri' => $request['redirect_uri'],
            'scope' => implode(' ', $request['scopes']),
            'state' => $request['state'],
            'code_challenge' => $request['code_challenge'],
            'code_challenge_method' => 'S256',
            'resource' => $request['resource'],
        ];
        $grantable = array_values(array_filter(
            $request['scopes'],
            static fn (string $scope): bool => current_user_can(Scopes::capability($scope))
        ));
        ?>
        <div class="wrap nucleo-admin">
            <?php echo Icons::sprite(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statico del plugin, nome icona validato da Icons. ?>
            <div class="nc-card" style="max-width: 560px; margin: 0 auto;">
                <div class="nc-card__head">
                    <div class="nc-page__intro">
                        <?php echo Icons::tile('nc-accesso'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statico del plugin, nome icona validato da Icons. ?>
                        <div>
                            <span class="nc-page__kicker"><?php esc_html_e('Nucleo · access request', 'nucleo'); ?></span>
                            <h1 tabindex="-1" class="nc-page__title"><?php esc_html_e('Authorize AI agent', 'nucleo'); ?></h1>
                        </div>
                    </div>
                </div>
                <div class="nc-card__body" style="gap: 16px;">
                    <p class="nc-muted" style="margin: 0;">
                        <strong><?php echo esc_html($clientName); ?></strong>
                        <?php esc_html_e('wants to connect to this site.', 'nucleo'); ?>
                    </p>

                    <div class="nc-card" style="background: var(--nc-surface-2);">
                        <div class="nc-card__body" style="gap: 6px;">
                            <span class="nc-label"><?php esc_html_e('Client domain', 'nucleo'); ?></span>
                            <div class="nc-auth-domain" style="font-size: 18px; font-weight: 600;"><?php echo esc_html($domain); ?></div>
                            <div class="nc-muted">
                                <?php esc_html_e('Redirects to:', 'nucleo'); ?> <?php echo esc_html($request['redirect_uri']); ?>
                            </div>
                        </div>
                    </div>

                    <p class="nc-muted" style="margin: 0;">
                        <?php esc_html_e('Will act with the permissions of:', 'nucleo'); ?>
                        <strong><?php echo esc_html($user->display_name); ?></strong>
                        <?php if ($roleLabels !== '') : ?>
                            <span>(<?php echo esc_html($roleLabels); ?>)</span>
                        <?php endif; ?>
                    </p>
                    <p class="nc-muted" style="margin: 0;">
                        <?php esc_html_e('Resource:', 'nucleo'); ?> <code><?php echo esc_html($request['resource']); ?></code>
                    </p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>">
                        <?php wp_nonce_field(self::NONCE); ?>
                        <?php foreach ($hidden as $field => $value) : ?>
                            <input type="hidden" name="<?php echo esc_attr($field); ?>" value="<?php echo esc_attr($value); ?>">
                        <?php endforeach; ?>

                        <fieldset style="border: 0; margin: 0; padding: 0;">
                            <legend class="nc-label"><?php esc_html_e('Requested permissions', 'nucleo'); ?></legend>
                            <?php if ($grantable === []) : ?>
                                <p class="nc-muted"><?php esc_html_e('You do not have the permissions needed for any of the requested scopes.', 'nucleo'); ?></p>
                            <?php endif; ?>
                            <ul style="list-style: none; margin: 0; padding: 0;">
                                <?php foreach ($request['scopes'] as $scope) : ?>
                                    <?php $allowed = in_array($scope, $grantable, true); ?>
                                    <li class="<?php echo $allowed ? '' : 'nc-muted'; ?>" style="padding: 12px 0; border-top: 1px solid var(--nc-border);">
                                        <label class="nc-check" style="display: flex; gap: 12px; align-items: flex-start;">
                                            <input type="checkbox" name="grant[]" value="<?php echo esc_attr($scope); ?>"
                                                <?php checked($allowed); ?> <?php disabled(!$allowed); ?>>
                                            <span>
                                                <strong><?php echo esc_html(self::scopeLabel($scope)); ?></strong>
                                                <span class="nc-code"><?php echo esc_html($scope); ?></span>
                                                <?php if (!$allowed) : ?>
                                                    <br><span><?php esc_html_e('Not available: your role does not have the permission needed.', 'nucleo'); ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </label>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </fieldset>

                        <div class="nc-row" style="justify-content: flex-end; margin-top: 16px;">
                            <button type="submit" name="decision" value="deny" class="nc-btn nc-btn--secondary">
                                <?php esc_html_e('Cancel', 'nucleo'); ?>
                            </button>
                            <button type="submit" name="decision" value="allow" class="nc-btn nc-btn--primary" <?php disabled($grantable === []); ?>>
                                <?php esc_html_e('Approve', 'nucleo'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
}
