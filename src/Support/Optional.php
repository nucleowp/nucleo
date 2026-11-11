<?php

declare(strict_types=1);

namespace Nucleo\Support;

defined('ABSPATH') || exit;

/**
 * Calls a static method of a class that only the Pro package contains.
 * In the Free package the class is missing and the call returns null.
 */
final class Optional
{
    public static function call(string $class, string $method, mixed ...$args): mixed
    {
        $callable = [$class, $method];
        if (!class_exists($class) || !is_callable($callable)) {
            return null;
        }

        return $callable(...$args);
    }
}
