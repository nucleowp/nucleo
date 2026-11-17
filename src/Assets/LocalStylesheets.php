<?php

declare(strict_types=1);

namespace Nucleo\Assets;

defined('ABSPATH') || exit;

use Nucleo\Compiler\CssFiles;
use Nucleo\Storage\ArtifactStoreFactory;

/**
 * Raccoglie i fogli CSS locali del sito (tema, plugin attivi, core, store Nucleo) come mappa url => CSS.
 */
final class LocalStylesheets
{
    private const MAX_FILES = 2000;
    private const MAX_BYTES = 15 * 1024 * 1024;

    /**
     * @return array<string, string> url del foglio => contenuto
     */
    public static function all(): array
    {
        $sheets = [];
        $seen = [];
        $bytes = 0;
        foreach (self::roots() as $root) {
            foreach (self::files($root) as $path) {
                $real = realpath($path);
                if ($real === false || isset($seen[$real])) {
                    continue;
                }
                $url = self::url($real);
                if ($url === null) {
                    continue;
                }
                if (count($seen) >= self::MAX_FILES) {
                    return $sheets;
                }
                $size = (int) filesize($real);
                if ($bytes + $size > self::MAX_BYTES) {
                    return $sheets;
                }
                $seen[$real] = true;
                $css = file_get_contents($real);
                if ($css === false) {
                    continue;
                }
                $bytes += $size;
                $sheets[$url] = $css;
            }
        }

        return $sheets;
    }

    /**
     * Solo i fogli indicati, nell'ordine ricevuto: quelli che la pagina misurata carica.
     * Gli URL che non puntano a un file .css locale del sito si saltano. Si salta anche il CSS compilato
     * dei documenti: con il critical attivo resta nella pagina, inline, e le sue regole valgono per una pagina sola.
     *
     * @param list<mixed> $urls
     * @return array<string, string> url del foglio => contenuto
     */
    public static function only(array $urls): array
    {
        $sheets = [];
        $bytes = 0;
        foreach (array_slice($urls, 0, self::MAX_FILES) as $url) {
            $path = is_string($url) ? LocalFile::path($url) : null;
            if ($path === null || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'css' || CssFiles::isDocumentCss($path)) {
                continue;
            }
            $bytes += (int) filesize($path);
            if ($bytes > self::MAX_BYTES) {
                break;
            }
            $css = file_get_contents($path);
            if ($css !== false) {
                // Senza query né frammento: la stessa chiave per ?ver=1 e ?ver=2.
                $sheets[explode('?', explode('#', $url, 2)[0], 2)[0]] = $css;
            }
        }

        return $sheets;
    }

    /** @return list<string> */
    private static function roots(): array
    {
        $roots = [get_stylesheet_directory(), get_template_directory()];
        $plugins = get_option('active_plugins', []);
        foreach (is_array($plugins) ? $plugins : [] as $plugin) {
            if (!is_string($plugin)) {
                continue;
            }
            $folder = dirname($plugin);
            if ($folder === '.' || str_contains($folder, '..')) {
                continue;
            }
            $roots[] = WP_PLUGIN_DIR . '/' . $folder;
        }
        // Nucleo sempre, anche se caricato senza comparire in active_plugins (mu-plugin, test).
        $roots[] = rtrim(NUCLEO_DIR, '/');
        $roots[] = ABSPATH . WPINC . '/css';
        $roots[] = ArtifactStoreFactory::base();

        return $roots;
    }

    /**
     * Percorsi dei *.css sotto $root, saltando le cartelle node_modules.
     *
     * @return \Generator<int, string>
     */
    private static function files(string $root): \Generator
    {
        if (!is_dir($root)) {
            return;
        }
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    static fn (\SplFileInfo $file): bool => !($file->isDir() && $file->getFilename() === 'node_modules')
                )
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'css') {
                    yield $file->getPathname();
                }
            }
        } catch (\UnexpectedValueException) {
            // Cartella non leggibile: se ne occupa il resto della raccolta.
            return;
        }
    }

    /** URL del file: sotto WP_CONTENT_DIR → content_url(), sotto ABSPATH → site_url('/'), altrimenti null. */
    private static function url(string $path): ?string
    {
        $content = realpath(WP_CONTENT_DIR);
        if (is_string($content) && str_starts_with($path, $content . '/')) {
            return rtrim(content_url(), '/') . '/' . self::encode(substr($path, strlen($content) + 1));
        }
        $abspath = realpath(ABSPATH);
        if (is_string($abspath) && str_starts_with($path, $abspath . '/')) {
            return site_url('/') . self::encode(substr($path, strlen($abspath) + 1));
        }

        return null;
    }

    private static function encode(string $relative): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $relative)));
    }
}
