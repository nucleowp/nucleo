<?php

declare(strict_types=1);

namespace Nucleo\Site;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/** Legge e scrive i file del sito in una cartella, sottocartelle incluse. */
final class SiteFolder
{
    /** @param array<string,string> $files percorso relativo → contenuto */
    public static function write(string $dir, array $files): void
    {
        $root = rtrim($dir, '/');
        foreach ($files as $path => $content) {
            self::assertRelative($path);

            $target = $root . '/' . $path;
            wp_mkdir_p(dirname($target));
            if (file_put_contents($target, $content) === false) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new RuntimeException(sprintf(/* translators: %s: file path. */ __('Cannot write file: %s', 'nucleo'), $target));
            }
        }
    }

    /** @return array<string,string> percorso relativo (con /) → contenuto, solo file .json */
    public static function read(string $dir): array
    {
        if (!is_dir($dir)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: directory path. */ __('Directory does not exist: %s', 'nucleo'), $dir));
        }

        $root = rtrim($dir, '/');
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || !$entry->isFile() || $entry->getExtension() !== 'json') {
                continue;
            }

            $content = file_get_contents($entry->getPathname());
            if ($content === false) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new RuntimeException(sprintf(/* translators: %s: file path. */ __('Cannot read file: %s', 'nucleo'), $entry->getPathname()));
            }
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            $files[str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = $content;
        }
        ksort($files);

        return $files;
    }

    /** Rifiuta percorsi assoluti, con segmenti "..", o con backslash. */
    private static function assertRelative(string $path): void
    {
        if (
            $path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
            || preg_match('/^[A-Za-z]:/', $path) === 1 || in_array('..', explode('/', $path), true)
        ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: file path. */ __('Invalid path: %s', 'nucleo'), $path));
        }
    }
}
