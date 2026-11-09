<?php

declare(strict_types=1);

namespace Nucleo\OAuth;

/**
 * Autenticazione delle richieste con "Authorization: Bearer nca_…".
 * Un token non valido lascia l'utente invariato e non imposta CurrentToken.
 */
final class BearerAuth
{
    public static function register(): void
    {
        add_filter('determine_current_user', [self::class, 'resolve'], 20);
    }

    public static function resolve(mixed $userId): mixed
    {
        CurrentToken::clear();

        // Il token vale solo sulle rotte REST di Nucleo: altrove l'utente resta quello di WordPress.
        if (!self::appliesToRequest()) {
            return $userId;
        }

        $token = self::bearerToken();
        if ($token === null) {
            return $userId;
        }

        $info = (new Server())->validateAccess($token);
        if ($info === null) {
            return $userId;
        }

        CurrentToken::set($info);

        return $info['user_id'];
    }

    /**
     * Vero solo per le richieste che WordPress instrada a /nucleo/v1/…. Se c'è rest_route, decide solo quello,
     * perché WordPress lo preferisce al percorso; altrimenti il percorso deve iniziare con <home>/<prefisso>/nucleo/v1/.
     * Pura: riceve i dati della richiesta, quindi si testa senza WordPress.
     */
    public static function appliesTo(string $uri, string $restPrefix, ?string $restRoute, string $homePath = '/'): bool
    {
        if ($restRoute !== null) {
            return str_starts_with($restRoute, '/nucleo/v1/');
        }

        $prefix = trim($restPrefix, '/');
        $path = wp_parse_url($uri, PHP_URL_PATH);
        if ($prefix === '' || !is_string($path)) {
            return false;
        }
        $home = trim($homePath, '/');
        $base = '/' . ($home === '' ? '' : $home . '/') . $prefix . '/nucleo/v1/';

        return str_starts_with($path, $base);
    }

    private static function appliesToRequest(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- legge la rotta REST per decidere se applicare l'autenticazione Bearer.
        if (isset($_GET['rest_route']) && !is_string($_GET['rest_route'])) {
            return false;
        }
        $uri = sanitize_text_field(wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '')));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- legge la rotta REST per decidere se applicare l'autenticazione Bearer.
        $route = isset($_GET['rest_route']) ? sanitize_text_field(wp_unslash($_GET['rest_route'])) : null;
        $home = wp_parse_url(home_url(), PHP_URL_PATH);

        return self::appliesTo($uri, rest_get_url_prefix(), $route, is_string($home) ? $home : '/');
    }

    private static function bearerToken(): ?string
    {
        $header = sanitize_text_field(wp_unslash((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')));
        if (!is_string($header) || preg_match('/\A[Bb]earer\s+(nca_[A-Za-z0-9_\-]+)\z/', trim($header), $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
