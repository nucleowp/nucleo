<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

defined('ABSPATH') || exit;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Stile di focus che annulla l'indicatore di focus (outline none, trasparente o alpha 0). */
final class FocusHidden implements Rule
{
    public function id(): string
    {
        return 'a11y.focus.hidden';
    }

    public function severity(): Severity
    {
        return Severity::Error;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->styles as $style) {
            if (!str_ends_with($style['prop'], 'Focus') || !self::hides($style['value'])) {
                continue;
            }

            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                sprintf(
                    /* translators: 1: style property, 2: style value. */
                    __('The focus style %1$s hides the focus indicator (value "%2$s").', 'nucleo'),
                    $style['prop'],
                    $style['value'],
                ),
                [['doc' => $style['doc'], 'id' => $style['id']]],
            );
        }

        return $issues;
    }

    /** Valore che rende invisibile il focus: none, transparent, 0, rgba/hsla o #RGBA/#RRGGBBAA con alpha 0. */
    private static function hides(string $value): bool
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['none', 'transparent', '0'], true)) {
            return true;
        }

        if (preg_match('/^#([0-9a-f]{4}|[0-9a-f]{8})$/', $value, $m) === 1) {
            $alpha = strlen($m[1]) === 4 ? substr($m[1], 3) : substr($m[1], 6);

            return hexdec($alpha) == 0;
        }

        if (preg_match('/^(rgba|hsla)\((.*)\)$/', $value, $m) === 1) {
            $parts = explode(',', $m[2]);
            if (count($parts) !== 4) {
                return false;
            }
            $alpha = rtrim(trim($parts[3]), '%');

            return is_numeric($alpha) && (float) $alpha === 0.0;
        }

        return false;
    }
}
