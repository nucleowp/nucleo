<?php

declare(strict_types=1);

namespace Nucleo\OAuth;

defined('ABSPATH') || exit;

/** Token OAuth opachi. Il token in chiaro non si salva: si salva solo hash(). */
final class TokenFactory
{
    public static function random(string $prefix, int $bytes = 32): string
    {
        if ($bytes < 16) {
            throw new \InvalidArgumentException('At least 16 bytes of entropy are needed.');
        }

        return $prefix . rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
