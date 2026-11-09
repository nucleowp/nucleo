<?php

declare(strict_types=1);

namespace Nucleo\Abilities;

use Nucleo\Support\AtomicCounter;

/** Limite a finestra fissa su un contatore atomico: le richieste in parallelo non lo superano. */
final class RateLimiter
{
    public const WINDOW = 60;

    /**
     * Registra una chiamata. Restituisce false se la chiave ha già raggiunto il limite nella finestra corrente.
     */
    public static function hit(string $key, int $limit, int $window = self::WINDOW): bool
    {
        $window = max(1, $window);
        // Indice della finestra nella chiave: ogni finestra parte da zero, senza leggere e riscrivere.
        $bucket = intdiv(time(), $window);

        return AtomicCounter::incr('rl|' . $key . '|' . $bucket, $window) <= $limit;
    }
}
