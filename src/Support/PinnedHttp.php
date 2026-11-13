<?php

declare(strict_types=1);

namespace Nucleo\Support;

defined('ABSPATH') || exit;

use Nucleo\OAuth\UrlGuard;

/**
 * Richieste HTTP verso URL forniti da utenti o documenti, protette da SSRF e da DNS rebinding.
 * Ogni passo verifica l'URL con UrlGuard, risolve l'host una volta e fissa la connessione all'IP verificato
 * (CURLOPT_RESOLVE). WordPress non segue redirect: con $maxRedirects > 0 i 3xx di una GET si seguono qui,
 * verificando e fissando di nuovo ogni Location.
 */
final class PinnedHttp
{
    /**
     * @param array<string,mixed> $args argomenti di wp_safe_remote_request; method e redirection sono imposti qui
     * @return array<string,mixed>|\WP_Error
     */
    public static function request(string $method, string $url, array $args, UrlGuard $guard, int $maxRedirects = 0): array|\WP_Error
    {
        for ($hop = 0;; $hop++) {
            if (!$guard->isSafePublicUrl($url)) {
                return new \WP_Error('nucleo_unsafe_url', __('URL not allowed: it must be https and point to a public host.', 'nucleo'));
            }
            $response = self::once($method, $url, $args);
            if (is_wp_error($response)) {
                return $response;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $location = wp_remote_retrieve_header($response, 'location');
            if ($method !== 'GET' || $code < 300 || $code > 399 || !is_string($location) || $location === '' || $hop >= $maxRedirects) {
                return $response;
            }
            $url = \WP_Http::make_absolute_url($location, $url);
        }
    }

    /** Primo IPv4 dell'host, solo se tutti gli IP risolti sono pubblici. */
    public static function pinnedIp(string $host): ?string
    {
        $ips = HostResolver::resolve($host);
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!UrlGuard::isPublicIp($ip)) {
                return null;
            }
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $ip;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    private static function once(string $method, string $url, array $args): array|\WP_Error
    {
        $host = strtolower(trim((string) wp_parse_url($url, PHP_URL_HOST), '[]'));
        $port = (int) (wp_parse_url($url, PHP_URL_PORT) ?? 443);

        // Un IP letterale è già stato verificato da UrlGuard: il pin serve solo per gli host DNS.
        $pin = null;
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            // Senza CURLOPT_RESOLVE il pin non si applica: meglio rifiutare che connettersi a un IP non verificato.
            $ip = defined('CURLOPT_RESOLVE') && function_exists('curl_init') ? self::pinnedIp($host) : null;
            if ($ip === null) {
                return new \WP_Error('nucleo_unpinned_host', __('The host could not be resolved to a verified public address.', 'nucleo'));
            }
            $pin = static function ($handle, $requestArgs, $requestUrl) use ($host, $port, $ip): void {
                if (strtolower((string) wp_parse_url((string) $requestUrl, PHP_URL_HOST)) === $host) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- protezione SSRF: fissa l'IP già validato, WordPress non ha un'alternativa.
                    curl_setopt($handle, CURLOPT_RESOLVE, ["$host:$port:$ip"]);
                }
            };
            add_action('http_api_curl', $pin, 10, 3);
        }

        try {
            return wp_safe_remote_request($url, ['method' => $method, 'redirection' => 0] + $args);
        } finally {
            if ($pin !== null) {
                remove_action('http_api_curl', $pin, 10);
            }
        }
    }
}
