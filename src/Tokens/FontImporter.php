<?php

declare(strict_types=1);

namespace Nucleo\Tokens;

defined('ABSPATH') || exit;

use Nucleo\Compiler\TokensPublisher;
use Nucleo\Services;
use WP_Error;

/**
 * Importa una famiglia Google Fonts in locale: CSS da fonts.googleapis.com, woff2 solo da fonts.gstatic.com.
 * I file vanno in uploads/nucleo/fonts, l'elenco in nucleo_fonts (autoload off).
 */
final class FontImporter
{
    private const OPTION = 'nucleo_fonts';
    private const SUBSETS_OPTION = 'nucleo_font_subsets';
    private const DEFAULT_SUBSETS = ['latin', 'latin-ext'];
    private const BODY_WEIGHT = 400;
    private const HEADING_WEIGHT = 700;
    private const CSS_ENDPOINT = 'https://fonts.googleapis.com/css2';
    private const FONT_HOST = 'fonts.gstatic.com';
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    private const TIMEOUT = 10;
    private const MAX_BYTES = 2097152;
    private const MAX_WEIGHTS = 9;
    private const WOFF2_MAGIC = 'wOF2';
    private const FAMILY_PATTERN = '/^[\pL\pN]+(?: [\pL\pN]+){0,7}$/u';

    /**
     * @param list<int> $weights
     * @return list<array{family:string,weight:int,style:string,file:string,url:string,range:string}>|WP_Error
     */
    public function import(string $family, array $weights, bool $italic = false): array|WP_Error
    {
        $family = trim($family);
        if (preg_match(self::FAMILY_PATTERN, $family) !== 1) {
            return new WP_Error('invalid_family', __('Invalid family name: use letters and digits, with single spaces.', 'nucleo'));
        }

        foreach ($weights as $weight) {
            if (!is_int($weight) || $weight < 100 || $weight > 900 || $weight % 100 !== 0) {
                return new WP_Error('invalid_weights', __('Weights must be integers that are multiples of 100 between 100 and 900.', 'nucleo'));
            }
        }
        $weights = array_values(array_unique($weights));
        sort($weights);
        if ($weights === [] || count($weights) > self::MAX_WEIGHTS) {
            return new WP_Error('invalid_weights', sprintf(/* translators: %d: maximum number of weights. */ __('Use between 1 and %d weights.', 'nucleo'), self::MAX_WEIGHTS));
        }

        $css = $this->fetch(self::cssUrl($family, $weights, $italic), 'text/css');
        if ($css instanceof WP_Error) {
            return $css;
        }

        $faces = self::parseFaces($css);
        if ($faces instanceof WP_Error) {
            return $faces;
        }

        $uploads = wp_upload_dir();
        if ($uploads['error'] !== false) {
            return new WP_Error('uploads_unavailable', __('Uploads folder unavailable.', 'nucleo'));
        }
        $dir = $uploads['basedir'] . '/nucleo/fonts';
        if (!wp_mkdir_p($dir)) {
            return new WP_Error('uploads_unavailable', __('Font folder cannot be created.', 'nucleo'));
        }

        $slug = sanitize_title($family);
        $entries = [];
        $used = [];
        foreach ($faces as $face) {
            $bytes = $this->fetch($face['src'], 'font/woff2');
            if ($bytes instanceof WP_Error) {
                return $bytes;
            }
            if (!str_starts_with($bytes, self::WOFF2_MAGIC)) {
                return new WP_Error('font_format', __('The downloaded file is not a woff2.', 'nucleo'));
            }

            // Google separa i sottoinsiemi (latin, cyrillic...) in più @font-face dello stesso peso: il nome resta unico.
            $name = $slug . '-' . $face['weight'] . '-' . $face['style'] . ($face['subset'] !== '' ? '-' . $face['subset'] : '');
            $count = $used[$name] ?? 0;
            $used[$name] = $count + 1;
            if ($count > 0) {
                $name .= '-' . ($count + 1);
            }

            $file = $dir . '/' . $name . '.woff2';
            if (file_put_contents($file, $bytes) === false) {
                return new WP_Error('font_write', __('Writing the font failed.', 'nucleo'));
            }

            $entries[] = [
                'family' => $family,
                'weight' => $face['weight'],
                'style' => $face['style'],
                'file' => $file,
                'url' => $uploads['baseurl'] . '/nucleo/fonts/' . $name . '.woff2',
                'range' => $face['range'],
            ];
        }

        self::save($family, $entries, $dir);
        TokensPublisher::publish();

        return $entries;
    }

    /** @font-face locali con font-display:swap, una per file installato. */
    public static function fontFaceCss(): string
    {
        $rules = '';
        $faces = self::installed();
        foreach ($faces as $face) {
            $rules .= sprintf(
                "@font-face{font-family:'%s';font-style:%s;font-weight:%d;font-display:swap;src:url(%s) format('woff2');%s}",
                $face['family'],
                $face['style'],
                $face['weight'],
                $face['url'],
                $face['range'] !== '' ? 'unicode-range:' . $face['range'] . ';' : '',
            );
        }
        foreach (array_unique(array_column($faces, 'family')) as $family) {
            $rules .= FontFallback::css($family);
        }

        return $rules;
    }

    /** URL del file della famiglia usata da font.body (peso 400 normale se presente), altrimenti il primo disponibile. */
    public static function preload(): ?string
    {
        $urls = self::preloads();

        return $urls[0] ?? null;
    }

    /**
     * URL dei file da precaricare: font.body a peso 400 e font.heading a peso 700, senza duplicati.
     *
     * @return list<string>
     */
    public static function preloads(): array
    {
        return self::preloadUrls(
            self::installed(),
            Services::tokens()->raw('$font.body') ?? '',
            Services::tokens()->raw('$font.heading') ?? '',
        );
    }

    /**
     * Scelta dei file da precaricare, senza I/O. Il body ricade sul primo file installato se lo stack non ne trova nessuno.
     *
     * @param list<array{family:string,weight:int,style:string,file:string,url:string,range:string}> $faces
     * @return list<string>
     */
    public static function preloadUrls(array $faces, string $bodyStack, string $headingStack): array
    {
        if ($faces === []) {
            return [];
        }

        $urls = [];
        $body = self::faceUrl($faces, $bodyStack, self::BODY_WEIGHT) ?? $faces[0]['url'];
        $heading = self::faceUrl($faces, $headingStack, self::HEADING_WEIGHT);
        foreach ([$body, $heading] as $url) {
            if ($url !== null && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Sottoinsiemi da scaricare (opzione nucleo_font_subsets): minuscoli, senza duplicati; vuota o non valida → latin, latin-ext.
     *
     * @return list<string>
     */
    public static function subsets(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = strtolower(trim($item));
            if (preg_match('/^[a-z0-9-]+$/', $item) === 1 && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return $out === [] ? self::DEFAULT_SUBSETS : $out;
    }

    /**
     * File della prima famiglia dello stack presente: peso richiesto normale, altrimenti un file normale, altrimenti il primo.
     *
     * @param list<array{family:string,weight:int,style:string,file:string,url:string,range:string}> $faces
     */
    private static function faceUrl(array $faces, string $stack, int $weight): ?string
    {
        foreach (explode(',', $stack) as $name) {
            $name = strtolower(trim($name, " \t\"'"));
            $matches = array_values(array_filter(
                $faces,
                static fn (array $face): bool => strtolower($face['family']) === $name,
            ));
            if ($matches === []) {
                continue;
            }
            foreach ($matches as $face) {
                if ($face['style'] === 'normal' && $face['weight'] === $weight) {
                    return $face['url'];
                }
            }
            foreach ($matches as $face) {
                if ($face['style'] === 'normal') {
                    return $face['url'];
                }
            }

            return $matches[0]['url'];
        }

        return null;
    }

    /** @param list<int> $weights */
    private static function cssUrl(string $family, array $weights, bool $italic): string
    {
        $axis = $italic
            ? 'ital,wght@' . implode(';', array_map(static fn (int $w): string => "1,$w", $weights))
            : 'wght@' . implode(';', $weights);

        return self::CSS_ENDPOINT . '?family=' . str_replace(' ', '+', $family) . ':' . $axis . '&display=swap';
    }

    /**
     * Keeps only the subsets from the nucleo/font_subsets filter (default latin, latin-ext):
     * every subset is one more download, and shared hosting allows 30 seconds per request.
     * The option nucleo_font_subsets gives the starting list, the filter has the last word.
     *
     * @return list<array{weight:int,style:string,src:string,range:string,subset:string}>|WP_Error
     */
    private static function parseFaces(string $css): array|WP_Error
    {
        if (preg_match_all('#(?:/\*\s*([a-z0-9-]+)\s*\*/\s*)?@font-face\s*\{([^}]*)\}#i', $css, $blocks, PREG_SET_ORDER) === 0) {
            return new WP_Error('font_format', __('No @font-face in the Google Fonts response.', 'nucleo'));
        }
        $allowed = apply_filters('nucleo/font_subsets', self::subsets(get_option(self::SUBSETS_OPTION, [])));
        $allowed = is_array($allowed) ? $allowed : self::DEFAULT_SUBSETS;

        $faces = [];
        foreach ($blocks as $match) {
            $subset = strtolower($match[1]);
            if ($subset !== '' && !in_array($subset, $allowed, true)) {
                continue;
            }
            $block = $match[2];
            $weight = preg_match('/font-weight:\s*(\d{3})\b/', $block, $w) === 1 ? (int) $w[1] : 0;
            $style = preg_match('/font-style:\s*(normal|italic)\b/i', $block, $st) === 1 ? strtolower($st[1]) : 'normal';
            $src = preg_match('/url\(\s*([^)\s]+)\s*\)\s*format\(\s*[\'"]?woff2[\'"]?\s*\)/i', $block, $u) === 1
                ? trim($u[1], "'\"")
                : '';
            $range = preg_match('/unicode-range:\s*([U+0-9A-Fa-f?,\s-]+);?/', $block, $r) === 1 ? trim($r[1]) : '';

            if ($weight === 0 || $src === '') {
                return new WP_Error('font_format', __('An @font-face has no weight or woff2 source.', 'nucleo'));
            }
            $host = strtolower((string) wp_parse_url($src, PHP_URL_HOST));
            if (wp_parse_url($src, PHP_URL_SCHEME) !== 'https' || $host !== self::FONT_HOST) {
                return new WP_Error('font_host_rejected', sprintf(/* translators: %s: rejected host name. */ __('Font host not allowed: %s', 'nucleo'), $host));
            }

            $faces[] = ['weight' => $weight, 'style' => $style, 'src' => $src, 'range' => $range, 'subset' => $subset];
        }
        if ($faces === []) {
            return new WP_Error('font_format', __('No allowed subset in the Google Fonts response.', 'nucleo'));
        }

        return $faces;
    }

    /** @return string|WP_Error corpo della risposta */
    private function fetch(string $url, string $accept): string|WP_Error
    {
        $response = wp_safe_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
            'limit_response_size' => self::MAX_BYTES,
            'user-agent' => self::USER_AGENT,
            'headers' => ['Accept' => $accept],
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('font_network', sprintf(/* translators: %s: network error message. */ __('Download failed: %s', 'nucleo'), $response->get_error_message()));
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('font_network', sprintf(/* translators: %s: HTTP status code. */ __('HTTP response %s from the font download.', 'nucleo'), $code));
        }

        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > self::MAX_BYTES) {
            return new WP_Error('font_too_large', __('Font file larger than 2 MB.', 'nucleo'));
        }

        return $body;
    }

    /**
     * Sostituisce la famiglia nell'elenco e rimuove i file vecchi di quella famiglia (solo dentro la cartella dei font).
     * @param list<array{family:string,weight:int,style:string,file:string,url:string,range:string}> $entries
     */
    private static function save(string $family, array $entries, string $dir): void
    {
        $fresh = array_column($entries, 'file');
        $kept = [];
        foreach (self::installed() as $old) {
            if (strtolower($old['family']) !== strtolower($family)) {
                $kept[] = $old;
                continue;
            }
            if (!in_array($old['file'], $fresh, true) && str_starts_with($old['file'], $dir . '/') && is_file($old['file'])) {
                \Nucleo\Support\Filesystem::direct()->delete($old['file']);
            }
        }

        add_option(self::OPTION, [], '', false);
        update_option(self::OPTION, [...$kept, ...$entries], false);
    }

    /**
     * Elenco salvato, rivalidato: le voci malformate vengono ignorate prima di finire nel CSS.
     * @return list<array{family:string,weight:int,style:string,file:string,url:string,range:string}>
     */
    public static function installed(): array
    {
        $out = [];
        foreach ((array) get_option(self::OPTION, []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $family = $entry['family'] ?? null;
            $weight = $entry['weight'] ?? null;
            $style = $entry['style'] ?? null;
            $file = $entry['file'] ?? null;
            $url = $entry['url'] ?? null;
            $range = $entry['range'] ?? '';
            if (
                is_string($family) && preg_match(self::FAMILY_PATTERN, $family) === 1
                && is_int($weight)
                && in_array($style, ['normal', 'italic'], true)
                && is_string($file)
                && is_string($url) && preg_match('#^https?://[^\s\'"()<>\\\\]+$#', $url) === 1
                && is_string($range) && preg_match('/^[U+0-9A-Fa-f?,\s-]*$/', $range) === 1
            ) {
                $out[] = ['family' => $family, 'weight' => $weight, 'style' => $style, 'file' => $file, 'url' => $url, 'range' => $range];
            }
        }

        return $out;
    }
}
