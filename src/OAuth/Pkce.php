<?php

declare(strict_types=1);

namespace Nucleo\OAuth;

/** PKCE (RFC 7636): solo il metodo S256. */
final class Pkce
{
    public static function verify(string $verifier, string $challenge): bool
    {
        if (preg_match('/\A[A-Za-z0-9\-._~]{43,128}\z/', $verifier) !== 1) {
            return false;
        }
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($computed, $challenge);
    }
}
