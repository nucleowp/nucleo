<?php

declare(strict_types=1);

namespace Nucleo\Quality;

/** Rapporto di contrasto WCAG 2.x tra due colori esadecimali. */
final class Contrast
{
    private const HEX = '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D';

    /** Rapporto tra 1 e 21, null se uno dei due colori non è hex (#rgb o #rrggbb). */
    public static function ratio(string $a, string $b): ?float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        if ($la === null || $lb === null) {
            return null;
        }

        [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    private static function luminance(string $hex): ?float
    {
        if (preg_match(self::HEX, $hex, $m) !== 1) {
            return null;
        }

        $digits = $m[1];
        if (strlen($digits) === 3) {
            $digits = $digits[0] . $digits[0] . $digits[1] . $digits[1] . $digits[2] . $digits[2];
        }

        $linear = array_map(static function (string $channel): float {
            $v = hexdec($channel) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split($digits, 2));

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }
}
