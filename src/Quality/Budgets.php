<?php

declare(strict_types=1);

namespace Nucleo\Quality;

/** Budget di performance delle regole perf: valore predefinito e filtro per sito. */
final class Budgets
{
    /** Byte di CSS servito da Nucleo. */
    public const CSS = 76800;
    /** Byte di JS servito da Nucleo. */
    public const JS = 102400;
    /** Nodi DOM stimati. */
    public const DOM = 1500;

    /** @return array{css:int,js:int,dom:int} */
    public static function all(): array
    {
        return ['css' => self::get('css'), 'js' => self::get('js'), 'dom' => self::get('dom')];
    }

    /** @param 'css'|'js'|'dom' $name */
    public static function get(string $name): int
    {
        $default = match ($name) {
            'css' => self::CSS,
            'js' => self::JS,
            'dom' => self::DOM,
        };

        return function_exists('apply_filters') ? (int) apply_filters('nucleo/quality/' . $name . '_budget', $default) : $default;
    }
}
