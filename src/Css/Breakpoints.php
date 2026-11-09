<?php

declare(strict_types=1);

namespace Nucleo\Css;

/** Mobile-first breakpoints. Keys are the document keys; values are min-width in px. */
final class Breakpoints
{
    public const KEYS = ['base', 'md', 'lg', 'xl'];
    public const DEFAULTS = ['base' => 0, 'md' => 768, 'lg' => 1024, 'xl' => 1920];

    /** @param array<string,int> $minWidths */
    public function __construct(public readonly array $minWidths = self::DEFAULTS)
    {
    }
}
