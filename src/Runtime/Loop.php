<?php

declare(strict_types=1);

namespace Nucleo\Runtime;

use WP_Post;
use WP_Term;

/**
 * Elemento corrente di un elenco (scheda di nc/query o nc/collection): un post, un termine (sorgente terms) o un
 * array di una sorgente dati.
 * Pila: una scheda può stare dentro un'altra scheda solo se il validatore lo permette, ma l'ordine resta corretto.
 */
final class Loop
{
    /** @var list<WP_Post|WP_Term|array<string,mixed>> */
    private static array $stack = [];

    /** @param WP_Post|WP_Term|array<string,mixed> $item */
    public static function push(WP_Post|WP_Term|array $item): void
    {
        self::$stack[] = $item;
    }

    public static function pop(): void
    {
        array_pop(self::$stack);
    }

    public static function active(): bool
    {
        return self::$stack !== [];
    }

    /**
     * Post corrente: il post più vicino nella pila. Una riga di un ripetitore (array) sopra una scheda lascia valere
     * il post della scheda. Null fuori da un elenco, con soli elementi array o dentro la scheda di un termine.
     */
    public static function post(): ?WP_Post
    {
        for ($i = count(self::$stack) - 1; $i >= 0; $i--) {
            if (self::$stack[$i] instanceof WP_Term) {
                return null;
            }
            if (self::$stack[$i] instanceof WP_Post) {
                return self::$stack[$i];
            }
        }

        return null;
    }

    /** Termine corrente: il termine più vicino nella pila (scheda di nc/collection con source terms). */
    public static function term(): ?WP_Term
    {
        for ($i = count(self::$stack) - 1; $i >= 0; $i--) {
            if (self::$stack[$i] instanceof WP_Term) {
                return self::$stack[$i];
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null elemento array corrente (sorgente dati del livello 4) */
    public static function item(): ?array
    {
        $top = self::$stack[count(self::$stack) - 1] ?? null;

        return is_array($top) ? $top : null;
    }
}
