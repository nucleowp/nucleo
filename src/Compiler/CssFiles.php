<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

use RuntimeException;

/**
 * File CSS di una pagina (p-<id>.<hash>.css). Il nuovo file si scrive accanto a quelli esistenti, con un file
 * temporaneo rinominato alla fine: un errore di scrittura lascia intatto il CSS valido. I file precedenti restano
 * per le pagine già in cache e si tolgono solo quando sono vecchi (KEEP_SECONDS) e oltre i più recenti (KEEP_FILES).
 */
final class CssFiles
{
    public const KEEP_SECONDS = 7 * 86400;
    public const KEEP_FILES = 5;

    /** Scrive il CSS e restituisce il nome del file. Lancia RuntimeException se il file non si scrive. */
    public static function write(string $dir, int $id, string $css, ?int $now = null): string
    {
        $name = 'p-' . $id . '.' . substr(sha1($css), 0, 8) . '.css';
        $file = $dir . '/' . $name;
        if (!is_file($file) || file_get_contents($file) !== $css) {
            $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- l'errore diventa un'eccezione con il percorso.
            $written = @file_put_contents($tmp, $css);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- rename atomico nella stessa cartella.
            if ($written === false || !@rename($tmp, $file)) {
                if (is_file($tmp)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- file temporaneo di questa scrittura.
                    unlink($tmp);
                }
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new RuntimeException(sprintf(/* translators: %s: CSS file path. */ __('Cannot write CSS: %s', 'nucleo'), $file));
            }
        }
        self::prune($dir, $id, $name, $now ?? time());

        return $name;
    }

    /** Toglie i file della pagina più vecchi di KEEP_SECONDS, tenendo sempre il corrente e i KEEP_FILES più recenti. */
    public static function prune(string $dir, int $id, string $current, int $now): void
    {
        $files = array_filter(glob($dir . '/p-' . $id . '.*.css') ?: [], static fn (string $f): bool => basename($f) !== $current);
        usort($files, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));
        foreach (array_slice($files, self::KEEP_FILES - 1) as $old) {
            if ((int) filemtime($old) < $now - self::KEEP_SECONDS) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- CSS di una versione superata, fuori dalla finestra della cache.
                unlink($old);
            }
        }
    }
}
