<?php

declare(strict_types=1);

namespace Nucleo\Assets;

/**
 * Mappa l'URL di un asset al file locale (solo se sta sotto il sito) e alla sua origine.
 */
final class LocalFile
{
    /**
     * @return string|null percorso reale del file, oppure null se l'URL non punta a un file locale del sito
     */
    public static function path(string $url): ?string
    {
        $found = self::locate($url);
        if ($found === null) {
            return null;
        }
        [$kind, $rel] = $found;
        if ($rel === '' || str_contains($rel, "\0") || in_array('..', explode('/', $rel), true)) {
            return null;
        }

        $file = realpath(self::dirOf($kind) . '/' . $rel);
        if ($file === false || !is_file($file)) {
            return null;
        }
        foreach (self::roots() as $root) {
            if (str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return string "plugin:<cartella>", "theme:<cartella>", "core", "nucleo" oppure "external"
     */
    public static function origin(string $url): string
    {
        $found = self::locate($url);
        if ($found === null) {
            return 'external';
        }
        [$kind, $rel] = $found;
        if ($kind !== 'content') {
            return 'core';
        }

        $parts = explode('/', $rel, 3);
        if (count($parts) === 3 && !in_array($parts[1], ['', '.', '..'], true)) {
            if ($parts[0] === 'plugins') {
                return $parts[1] === 'nucleo' ? 'nucleo' : 'plugin:' . $parts[1];
            }
            if ($parts[0] === 'themes') {
                return 'theme:' . $parts[1];
            }
            // CSS e JS compilati da Nucleo vivono in uploads/nucleo.
            if ($parts[0] === 'uploads' && $parts[1] === 'nucleo') {
                return 'nucleo';
            }
        }

        return 'external';
    }

    /**
     * Riconosce il prefisso dell'URL e restituisce il tipo di cartella con il percorso relativo.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function locate(string $url): ?array
    {
        $clean = explode('?', explode('#', $url, 2)[0], 2)[0];
        $prefixes = [
            'content' => content_url(),
            'includes' => includes_url(),
            'admin' => admin_url(),
        ];
        foreach ($prefixes as $kind => $prefix) {
            $prefix = rtrim($prefix, '/') . '/';
            if (str_starts_with($clean, $prefix)) {
                return [(string) $kind, rawurldecode(substr($clean, strlen($prefix)))];
            }
        }

        return null;
    }

    private static function dirOf(string $kind): string
    {
        return match ($kind) {
            'content' => WP_CONTENT_DIR,
            'includes' => ABSPATH . 'wp-includes',
            default => ABSPATH . 'wp-admin',
        };
    }

    /** @return list<string> */
    private static function roots(): array
    {
        $roots = [realpath(ABSPATH), realpath(WP_CONTENT_DIR)];

        return array_values(array_filter($roots, 'is_string'));
    }
}
