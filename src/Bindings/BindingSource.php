<?php

declare(strict_types=1);

namespace Nucleo\Bindings;

/**
 * Values that are fixed for one document, read at compile time.
 * Null means the value depends on the request: the compiler emits an nc/bind slot.
 */
interface BindingSource
{
    public function staticValue(string $source, string $field): ?string;
}
