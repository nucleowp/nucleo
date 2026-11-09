<?php

declare(strict_types=1);

namespace Nucleo\OAuth;

/**
 * Endpoint REST OAuth: token, revoca e registrazione dinamica.
 * Gli errori seguono RFC 6749 ({error, error_description}) con status 400, salvo dove indicato.
 * Il testo in chiaro (http) è rifiutato con https_required, salvo negli ambienti di sviluppo.
 */
final class RestRoutes
{
    private const NAMESPACE = 'nucleo/v1';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/oauth/token', [
            'methods' => 'POST',
            'callback' => [self::class, 'handleToken'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NAMESPACE, '/oauth/revoke', [
            'methods' => 'POST',
            'callback' => [self::class, 'handleRevoke'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NAMESPACE, '/oauth/register', [
            'methods' => 'POST',
            'callback' => [self::class, 'handleRegister'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handleToken(\WP_REST_Request $request): \WP_REST_Response
    {
        $blocked = self::plainHttpError();
        if ($blocked !== null) {
            return $blocked;
        }

        $grant = self::param($request, 'grant_type');
        if ($grant !== 'authorization_code' && $grant !== 'refresh_token') {
            return self::error('unsupported_grant_type', __('grant_type must be authorization_code or refresh_token.', 'nucleo'));
        }

        $server = new Server();
        // Il client confidenziale si autentica con client_secret_post o client_secret_basic; quello pubblico no.
        $auth = Server::parseClientAuth(
            self::param($request, 'client_id'),
            self::param($request, 'client_secret'),
            self::authorizationHeader()
        );
        if ($auth === null) {
            return self::error('invalid_client', __('Client authentication is malformed.', 'nucleo'));
        }
        $clientId = $auth['client_id'];
        if ($clientId === '') {
            /* translators: %s: parameter name */
            return self::error('invalid_request', sprintf(__('Missing parameter: %s.', 'nucleo'), 'client_id'));
        }
        if (!$server->authenticateClient($clientId, $auth['method'], $auth['secret'])) {
            return self::error('invalid_client', __('Client authentication failed.', 'nucleo'));
        }

        if ($grant === 'authorization_code') {
            $missing = self::missing($request, ['code', 'code_verifier', 'redirect_uri']);
            if ($missing !== null) {
                return $missing;
            }
            $result = $server->exchangeCode(['client_id' => $clientId] + self::params($request, ['code', 'code_verifier', 'redirect_uri', 'resource']));
        } else {
            $missing = self::missing($request, ['refresh_token']);
            if ($missing !== null) {
                return $missing;
            }
            $result = $server->refresh(['client_id' => $clientId] + self::params($request, ['refresh_token', 'scope']));
        }

        if ($result instanceof \WP_Error) {
            return self::error($result->get_error_code(), $result->get_error_message());
        }

        return self::json($result, 200);
    }

    public static function handleRevoke(\WP_REST_Request $request): \WP_REST_Response
    {
        $blocked = self::plainHttpError();
        if ($blocked !== null) {
            return $blocked;
        }
        $missing = self::missing($request, ['token']);
        if ($missing !== null) {
            return $missing;
        }
        // RFC 7009: anche un token sconosciuto ottiene 200.
        (new Server())->revoke(self::param($request, 'token'));

        return self::json([], 200);
    }

    public static function handleRegister(\WP_REST_Request $request): \WP_REST_Response
    {
        $blocked = self::plainHttpError();
        if ($blocked !== null) {
            return $blocked;
        }
        if (!Server::dcrEnabled()) {
            return self::error('access_denied', __('Dynamic registration is disabled.', 'nucleo'), 403);
        }

        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = $request->get_body_params();
        }

        try {
            $client = (new Server())->registerClient([
                'client_name' => $body['client_name'] ?? '',
                'redirect_uris' => $body['redirect_uris'] ?? null,
            ], 'dcr');
        } catch (\InvalidArgumentException $e) {
            return self::error('invalid_client_metadata', $e->getMessage());
        }

        return self::json($client, 201);
    }

    /** Risposta https_required se la richiesta è in chiaro fuori dagli ambienti di sviluppo; altrimenti null. */
    private static function plainHttpError(): ?\WP_REST_Response
    {
        if (!Server::rejectsPlainHttp(is_ssl(), wp_get_environment_type())) {
            return null;
        }

        return self::error('https_required', __('HTTPS is required for OAuth requests. Behind a reverse proxy, check that it forwards X-Forwarded-Proto: https.', 'nucleo'));
    }

    /** Header Authorization grezzo (anche da REDIRECT_HTTP_AUTHORIZATION), vuoto se assente. */
    private static function authorizationHeader(): string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                return sanitize_text_field(wp_unslash($_SERVER[$key]));
            }
        }

        return '';
    }

    /** @param list<string> $keys */
    private static function missing(\WP_REST_Request $request, array $keys): ?\WP_REST_Response
    {
        foreach ($keys as $key) {
            if (self::param($request, $key) === '') {
                /* translators: %s: name of the missing OAuth parameter. */
                return self::error('invalid_request', sprintf(__('Missing parameter: %s.', 'nucleo'), $key));
            }
        }

        return null;
    }

    /**
     * @param list<string> $keys
     * @return array<string,string>
     */
    private static function params(\WP_REST_Request $request, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = self::param($request, $key);
        }

        return $out;
    }

    private static function param(\WP_REST_Request $request, string $key): string
    {
        $value = $request->get_param($key);

        return is_string($value) ? $value : '';
    }

    private static function error(int|string $code, string $description, int $status = 400): \WP_REST_Response
    {
        return self::json(['error' => $code, 'error_description' => $description], $status);
    }

    /** @param array<mixed> $data */
    private static function json(array $data, int $status): \WP_REST_Response
    {
        $response = new \WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
