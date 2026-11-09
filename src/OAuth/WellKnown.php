<?php

declare(strict_types=1);

namespace Nucleo\OAuth;

/** Metadati RFC 9728 (risorsa protetta) e RFC 8414 (authorization server), serviti su parse_request. */
final class WellKnown
{
    private const PROTECTED_RESOURCE_PATH = '.well-known/oauth-protected-resource';
    private const AUTHORIZATION_SERVER_PATH = '.well-known/oauth-authorization-server';

    public static function register(): void
    {
        add_action('parse_request', [self::class, 'serve']);
    }

    public static function serve(\WP $wp): void
    {
        $path = trim((string) $wp->request, '/');
        $document = match ($path) {
            self::PROTECTED_RESOURCE_PATH => self::protectedResource(),
            self::AUTHORIZATION_SERVER_PATH => self::authorizationServer(),
            default => null,
        };
        if ($document === null) {
            return;
        }

        wp_send_json($document);
    }

    /** @return array<string,mixed> */
    public static function protectedResource(): array
    {
        return [
            'resource' => rest_url('nucleo/v1/mcp'),
            'authorization_servers' => [home_url()],
            'scopes_supported' => Scopes::all(),
            'bearer_methods_supported' => ['header'],
        ];
    }

    /** @return array<string,mixed> */
    public static function authorizationServer(): array
    {
        $document = [
            'issuer' => home_url(),
            'authorization_endpoint' => admin_url('admin.php?page=nucleo-authorize'),
            'token_endpoint' => rest_url('nucleo/v1/oauth/token'),
            'revocation_endpoint' => rest_url('nucleo/v1/oauth/revoke'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => [Server::AUTH_NONE, Server::AUTH_POST, Server::AUTH_BASIC],
            'scopes_supported' => Scopes::all(),
            'client_id_metadata_document_supported' => true,
        ];
        if (Server::dcrEnabled()) {
            $document['registration_endpoint'] = rest_url('nucleo/v1/oauth/register');
        }

        return $document;
    }
}
