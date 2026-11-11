<?php

declare(strict_types=1);

namespace Nucleo\Tokens;

defined('ABSPATH') || exit;

/**
 * Font di ripiego locali (Arial, Times New Roman, Courier New) con size-adjust e override
 * calcolati dalle metriche di data/font-metrics.json. Il file si legge solo all'import, mai sul frontend.
 */
final class FontFallback
{
    /** Ripiego per categoria: x sans-serif, s serif, m monospace. */
    private const FALLBACKS = [
        'x' => 'Arial',
        's' => 'Times New Roman',
        'm' => 'Courier New',
    ];

    /** @var array<string, array<int, mixed>>|null */
    private static ?array $metrics = null;

    /** @font-face del ripiego della famiglia, stringa vuota se la famiglia non ha metriche. */
    public static function css(string $family): string
    {
        $pair = self::pair($family);
        if ($pair === null) {
            return '';
        }
        [$font, $fallbackName, $fallback] = $pair;

        $sizeAdjust = ($font[5] / $font[4]) / ($fallback[5] / $fallback[4]);
        $unit = $font[4] * $sizeAdjust;

        return sprintf(
            "@font-face{font-family:'%s';src:local('%s');size-adjust:%s%%;ascent-override:%s%%;descent-override:%s%%;line-gap-override:%s%%}",
            self::escape($family) . ' Fallback',
            self::escape($fallbackName),
            self::percent($sizeAdjust),
            self::percent($font[1] / $unit),
            self::percent(abs($font[2]) / $unit),
            self::percent($font[3] / $unit),
        );
    }

    /** Stack CSS con il ripiego subito dopo la famiglia; senza metriche restituisce la famiglia invariata. */
    public static function stack(string $family): string
    {
        if (self::pair($family) === null) {
            return $family;
        }
        $name = self::escape($family);

        return "'$name', '$name Fallback'";
    }

    /**
     * Inserisce il ripiego subito dopo la prima famiglia di uno stack CSS completo.
     * Se la prima famiglia non ha metriche o il ripiego c'è già, restituisce lo stack invariato.
     */
    public static function withFallback(string $stack): string
    {
        $parts = explode(',', $stack, 2);
        $first = trim($parts[0]);
        $family = trim($first, " \t\"'");
        if ($family === '' || self::pair($family) === null || str_contains($stack, $family . ' Fallback')) {
            return $stack;
        }
        $fallback = "'" . self::escape($family) . " Fallback'";

        return $first . ', ' . $fallback . (isset($parts[1]) ? ',' . $parts[1] : '');
    }

    /** @return array{0: array<int, mixed>, 1: string, 2: array<int, mixed>}|null metriche della famiglia, nome e metriche del ripiego */
    private static function pair(string $family): ?array
    {
        $metrics = self::metrics();
        $font = $metrics[$family] ?? null;
        if (!is_array($font) || !isset(self::FALLBACKS[$font[0] ?? ''])) {
            return null;
        }
        $fallbackName = self::FALLBACKS[$font[0]];
        $fallback = $metrics[$fallbackName] ?? null;
        if (!is_array($fallback)) {
            return null;
        }

        return [$font, $fallbackName, $fallback];
    }

    /** @return array<string, array<int, mixed>> */
    private static function metrics(): array
    {
        if (self::$metrics === null) {
            $path = dirname(__DIR__, 2) . '/data/font-metrics.json';
            $raw = is_file($path) ? file_get_contents($path) : false;
            $decoded = $raw === false ? null : json_decode($raw, true);
            self::$metrics = is_array($decoded) ? $decoded : [];
        }

        return self::$metrics;
    }

    /** Percentuale con al massimo 2 decimali, senza zeri finali. */
    private static function percent(float $ratio): string
    {
        return rtrim(rtrim(sprintf('%.2f', $ratio * 100), '0'), '.');
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
