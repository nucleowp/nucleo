<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

defined('ABSPATH') || exit;

use Nucleo\Services;
use RuntimeException;

/**
 * Bundle unico dei CSS dei blocchi registrati, nome con hash del contenuto.
 * La firma (mtime e percorsi di manifest e style) evita di rileggere i file a ogni salvataggio.
 */
final class BlockBundle
{
    private const OPTION = 'nucleo_blocks_bundle';

    /** URL del bundle corrente; lo ricostruisce se i blocchi sono cambiati o il file manca. */
    public static function url(): string
    {
        $uploads = self::uploads();
        $dir = $uploads['basedir'] . '/nucleo/css';
        $signature = self::signature();

        $stored = get_option(self::OPTION, []);
        if (
            is_array($stored) && ($stored['signature'] ?? null) === $signature
            && is_string($stored['name'] ?? null) && is_file($dir . '/' . $stored['name'])
        ) {
            return $uploads['baseurl'] . '/nucleo/css/' . $stored['name'];
        }

        $css = self::minify(self::concatenate());
        $name = 'blocks.' . substr(sha1($css), 0, 8) . '.css';
        $file = $dir . '/' . $name;
        wp_mkdir_p($dir);
        if (!is_file($file) && file_put_contents($file, $css) === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new RuntimeException(sprintf(/* translators: %s: file path */ __('Cannot write the CSS bundle: %s', 'nucleo'), $file));
        }
        // Pages still point to the previous bundle until they are recompiled:
        // keep it, drop older ones, and queue every page for recompilation.
        $previous = is_array($stored) && is_string($stored['name'] ?? null) ? $stored['name'] : null;
        foreach (glob($dir . '/blocks.*.css') ?: [] as $old) {
            if (!in_array(basename($old), [$name, $previous], true)) {
                \Nucleo\Support\Filesystem::direct()->delete($old);
            }
        }
        update_option(self::OPTION, ['signature' => $signature, 'name' => $name], false);
        if ($previous !== null && $previous !== $name) {
            RebuildQueue::enqueueAll();
        }

        return $uploads['baseurl'] . '/nucleo/css/' . $name;
    }

    /** Percorso del bundle corrente, lo stesso file di url(); null se non esiste su disco. */
    public static function file(): ?string
    {
        $uploads = self::uploads();
        $file = $uploads['basedir'] . '/nucleo/css/' . basename(self::url());

        return is_file($file) ? $file : null;
    }

    /** @return array{basedir:string,baseurl:string} */
    private static function uploads(): array
    {
        $uploads = wp_upload_dir();
        if ($uploads['error'] !== false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new RuntimeException(sprintf(/* translators: %s: error message */ __('Uploads folder not available: %s', 'nucleo'), $uploads['error']));
        }

        return $uploads;
    }

    /** Ordine alfabetico del nome blocco (registry->all() è già ordinato). */
    private static function concatenate(): string
    {
        $chunks = [];
        foreach (Services::registry()->all() as $manifest) {
            $style = $manifest->assets['style'] ?? null;
            if (!is_string($style)) {
                continue;
            }
            $file = $manifest->dir . '/' . $style;
            $css = is_file($file) ? file_get_contents($file) : false;
            if ($css === false) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new RuntimeException(sprintf(/* translators: %s: file path */ __('Block CSS is not readable: %s', 'nucleo'), $file));
            }
            $chunks[] = $css;
        }

        return implode("\n", $chunks);
    }

    private static function minify(string $css): string
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
        $css = preg_replace('/\s+/', ' ', $css) ?? $css;
        $css = preg_replace('/\s*([{};,])\s*/', '$1', $css) ?? $css;

        return trim($css);
    }

    private static function signature(): string
    {
        $parts = [];
        foreach (Services::registry()->all() as $manifest) {
            $files = [$manifest->dir . '/block.nucleo.json'];
            $style = $manifest->assets['style'] ?? null;
            if (is_string($style)) {
                $files[] = $manifest->dir . '/' . $style;
            }
            foreach ($files as $file) {
                $parts[] = $file . '@' . (is_file($file) ? (string) filemtime($file) : '-');
            }
        }

        return sha1(implode("\n", $parts));
    }
}
