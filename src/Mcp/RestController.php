<?php

declare(strict_types=1);

namespace Nucleo\Mcp;

defined('ABSPATH') || exit;

/**
 * Trasporto HTTP streamable di MCP: POST /wp-json/nucleo/v1/mcp con risposta application/json.
 * Autenticazione: utente loggato (token OAuth via BearerAuth, cookie o application password).
 */
final class RestController
{
    /** Transient impostato quando una richiesta non autenticata arriva senza header Authorization; letto da AgentsPage. */
    public const HEADER_MISSING_TRANSIENT = 'nucleo_oauth_auth_header_missing';

    private const NAMESPACE = 'nucleo/v1';
    private const ROUTE = '/nucleo/v1/mcp';

    public static function register(): void
    {
        add_filter('rest_request_after_callbacks', [self::class, 'addChallengeHeader'], 10, 3);

        // Plugin::boot() ci chiama dentro rest_api_init: in quel caso registra subito le route.
        if (did_action('rest_api_init') > 0) {
            self::routes();

            return;
        }
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/mcp', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    /** @return true|\WP_Error */
    public static function authorize(): bool|\WP_Error
    {
        if (\Nucleo\OAuth\Server::rejectsPlainHttp(is_ssl(), wp_get_environment_type())) {
            return new \WP_Error('https_required', 'HTTPS is required. Behind a reverse proxy, check that it forwards X-Forwarded-Proto: https.', ['status' => 400]);
        }

        if (is_user_logged_in()) {
            return true;
        }

        // Il token Bearer non arriva a PHP se il server toglie l'header Authorization: AgentsPage lo segnala.
        $headers = function_exists('apache_request_headers') ? apache_request_headers() : false;
        // Solo i due header di autorizzazione che bearerHeaderDropped controlla, sanificati.
        $server = [];
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $server[$key] = sanitize_text_field(wp_unslash($_SERVER[$key]));
            }
        }
        if (get_transient(self::HEADER_MISSING_TRANSIENT) === false && \Nucleo\OAuth\Server::bearerHeaderDropped($server, is_array($headers) ? $headers : [])) {
            set_transient(self::HEADER_MISSING_TRANSIENT, time(), DAY_IN_SECONDS);
        }

        return new \WP_Error('nucleo_mcp_unauthorized', __('Authentication required.', 'nucleo'), ['status' => 401]);
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $message = (new Server())->handleJson((string) $request->get_body());

        // Le notifiche non hanno risposta.
        $response = $message === null
            ? new \WP_REST_Response(null, 202)
            : new \WP_REST_Response($message, 200);
        $response->header('MCP-Protocol-Version', Server::PROTOCOL_VERSION);

        return $response;
    }

    /** Il 401 deve indicare il server di autorizzazione (RFC 9728), altrimenti il client non sa dove fare OAuth. */
    public static function addChallengeHeader(mixed $response, mixed $handler, mixed $request): mixed
    {
        if (!$request instanceof \WP_REST_Request || $request->get_route() !== self::ROUTE) {
            return $response;
        }
        if ($response instanceof \WP_Error) {
            $response = rest_convert_error_to_response($response);
        }
        if (!$response instanceof \WP_REST_Response || $response->get_status() !== 401) {
            return $response;
        }

        $metadata = home_url('/.well-known/oauth-protected-resource');
        $response->header('WWW-Authenticate', sprintf('Bearer resource_metadata="%s"', $metadata));

        return $response;
    }
}
