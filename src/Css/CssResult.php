<?php

declare(strict_types=1);

namespace Nucleo\Css;

final class CssResult
{
    /**
     * @param array<string,list<string>> $classes node id → extra classes for that node (e.g. ["nc-a2"] or ["nc-h3f9a1c"])
     * @param string $css minified CSS, base rules first, then one @media / @container block per breakpoint in ascending order
     */
    public function __construct(public readonly array $classes, public readonly string $css)
    {
    }
}
