<?php

declare(strict_types=1);

namespace Nucleo\Tokens;

interface TokenResolver
{
    /** "$color.primary" → "var(--nc-color-primary)". Non-token strings return unchanged. Unknown token → null. */
    public function resolve(string $value): ?string;

    public function isToken(string $value): bool;
}
