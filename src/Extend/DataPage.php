<?php

declare(strict_types=1);

namespace Nucleo\Extend;

defined('ABSPATH') || exit;

/** Una pagina di elementi restituita da una DataSource. */
final class DataPage
{
    /** @param list<array<string,scalar|null>> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
    ) {
    }
}
