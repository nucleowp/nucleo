<?php

declare(strict_types=1);

namespace Nucleo\OAuth;

/** Documento di metadati del client (Client ID Metadata Documents): validazione e normalizzazione. */
final class ClientMetadata
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * @param array<string,mixed> $doc
     * @return list<string> errori; lista vuota se il documento è valido
     */
    public static function validate(array $doc, string $url): array
    {
        $errors = [];

        if (($doc['client_id'] ?? null) !== $url) {
            $errors[] = __('client_id must match the URL of the metadata document', 'nucleo');
        }

        $uris = $doc['redirect_uris'] ?? null;
        if (!is_array($uris) || !array_is_list($uris) || $uris === []) {
            $errors[] = __('redirect_uris must be a list with at least one URI', 'nucleo');
        } else {
            foreach ($uris as $uri) {
                if (!is_string($uri) || !self::isAllowedRedirect($uri)) {
                    $errors[] = __('redirect_uris contains a URI that is not allowed', 'nucleo');
                    break;
                }
            }
        }

        if (
            array_key_exists('client_name', $doc)
            && (!is_string($doc['client_name']) || mb_strlen($doc['client_name']) > 200)
        ) {
            $errors[] = __('client_name must be a string of at most 200 characters', 'nucleo');
        }

        if (
            array_key_exists('logo_uri', $doc)
            && (!is_string($doc['logo_uri']) || !self::isHttps($doc['logo_uri']))
        ) {
            $errors[] = __('logo_uri must be an https URL', 'nucleo');
        }

        if (
            array_key_exists('token_endpoint_auth_method', $doc)
            && $doc['token_endpoint_auth_method'] !== 'none'
        ) {
            $errors[] = __('token_endpoint_auth_method must be "none"', 'nucleo');
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $doc
     * @return array{client_id:string,client_name:string,redirect_uris:list<string>,logo_uri?:string}
     */
    public static function normalize(array $doc): array
    {
        $normalized = [
            'client_id' => (string) ($doc['client_id'] ?? ''),
            'client_name' => (string) ($doc['client_name'] ?? ''),
            'redirect_uris' => array_values(is_array($doc['redirect_uris'] ?? null) ? $doc['redirect_uris'] : []),
        ];
        if (isset($doc['logo_uri'])) {
            $normalized['logo_uri'] = (string) $doc['logo_uri'];
        }

        return $normalized;
    }

    private static function isAllowedRedirect(string $uri): bool
    {
        $parts = wp_parse_url($uri);
        if (!is_array($parts) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        return ($scheme === 'https' && $host !== '')
            || ($scheme === 'http' && in_array($host, self::LOOPBACK_HOSTS, true));
    }

    private static function isHttps(string $url): bool
    {
        $parts = wp_parse_url($url);

        return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') !== '';
    }
}
