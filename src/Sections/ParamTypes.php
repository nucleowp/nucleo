<?php

declare(strict_types=1);

namespace Nucleo\Sections;

/** Tipi dei parametri di sezione (@types): nome del parametro e valore ammesso per ciascun tipo. */
final class ParamTypes
{
    /** @var list<string> */
    public const TYPES = ['text', 'html', 'link', 'color', 'boolean', 'image', 'list'];

    /** Numero massimo di voci di un parametro list. */
    public const MAX_LIST = 30;

    public static function known(mixed $type): bool
    {
        return is_string($type) && in_array($type, self::TYPES, true);
    }

    public static function validName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,31}\z/', $name) === 1;
    }

    /** Il valore è ammesso per il tipo (il tipo deve essere noto, vedi known()). */
    public static function matches(string $type, mixed $value): bool
    {
        return match ($type) {
            'text', 'html', 'link' => is_string($value),
            'color' => is_string($value) && str_starts_with($value, '$color.'),
            'boolean' => is_bool($value),
            'image' => is_array($value)
                && is_string($value['src'] ?? null)
                && (!array_key_exists('alt', $value) || is_string($value['alt'])),
            'list' => is_array($value)
                && array_values($value) === $value
                && count($value) <= self::MAX_LIST
                && count(array_filter($value, 'is_string')) === count($value),
            default => false,
        };
    }
}
