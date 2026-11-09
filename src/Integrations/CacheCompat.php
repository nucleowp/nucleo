<?php

declare(strict_types=1);

namespace Nucleo\Integrations;

/** Esclude dalla RUCSS e dalla CSS exclusion di WP Rocket il CSS compilato di Nucleo in uploads/nucleo/css. */
final class CacheCompat
{
    public static function register(): void
    {
        add_filter('rocket_rucss_safelist', [self::class, 'rucssSafelist']);
        add_filter('rocket_exclude_css', [self::class, 'excludeCss']);
    }

    /**
     * @param array<int, mixed> $list lista ricevuta dal filtro di WP Rocket
     * @return array<int, mixed>
     */
    public static function rucssSafelist(array $list): array
    {
        return self::withPattern($list);
    }

    /**
     * @param array<int, mixed> $list lista ricevuta dal filtro di WP Rocket
     * @return array<int, mixed>
     */
    public static function excludeCss(array $list): array
    {
        return self::withPattern($list);
    }

    /**
     * @param array<int, mixed> $list lista ricevuta dal filtro di WP Rocket
     * @return array<int, mixed>
     */
    private static function withPattern(array $list): array
    {
        $pattern = self::cssPattern();
        if (!in_array($pattern, $list, true)) {
            $list[] = $pattern;
        }

        return $list;
    }

    /** Percorso dei CSS compilati, come lo scrive DocumentService: <uploads>/nucleo/css/. */
    public static function cssPattern(): string
    {
        $path = wp_parse_url(wp_upload_dir(null, false)['baseurl'], PHP_URL_PATH);

        return untrailingslashit(is_string($path) ? $path : '') . '/nucleo/css/(.*).css';
    }
}
