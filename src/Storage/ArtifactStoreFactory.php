<?php

declare(strict_types=1);

namespace Nucleo\Storage;

defined('ABSPATH') || exit;

use RuntimeException;

/**
 * Chooses the PHP file store when the artifact directory is (or can be made) writable,
 * and the option store otherwise.
 *
 * Generazione: i file dello store sopravvivono a un reset o a un import del database. Ogni blog ha una generazione
 * nell'opzione autoload nucleo_store_generation e legge la cartella "<base>/<blog>-<generazione>". Senza generazione
 * legge la cartella base, come prima delle generazioni; il primo put crea la generazione (StoreMaintenance::claim).
 * Dopo un reset la generazione manca e i file vecchi restano in una cartella che nessuno legge.
 */
final class ArtifactStoreFactory
{
    public const GENERATION_OPTION = 'nucleo_store_generation';

    /** Cartella dello store: uploads/nucleo/store, dentro wp_upload_dir(). StorePaths sposta quella vecchia all'aggiornamento. */
    public static function base(): string
    {
        return wp_upload_dir(null, false)['basedir'] . '/nucleo/store';
    }

    public static function create(string $dir): ArtifactStore
    {
        $generation = self::generation();
        try {
            if ($generation !== '') {
                return new PhpFileStore(self::generationDir($dir, $generation));
            }
            // Senza WordPress (test unitari) non c'è generazione da creare.
            return function_exists('add_option')
                ? new PhpFileStore($dir, static fn (): string => StoreMaintenance::claim($dir))
                : new PhpFileStore($dir);
        } catch (RuntimeException) {
            return new OptionStore();
        }
    }

    /** Generazione del blog corrente, vuota se manca o non è valida. Legge alloptions: nessuna query. */
    public static function generation(): string
    {
        if (!function_exists('wp_load_alloptions')) {
            return '';
        }
        $value = wp_load_alloptions()[self::GENERATION_OPTION] ?? '';

        return is_string($value) && preg_match('/\A[a-z0-9]{1,16}\z/', $value) === 1 ? $value : '';
    }

    public static function generationDir(string $base, string $generation): string
    {
        $blog = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;

        return rtrim($base, '/\\') . '/' . $blog . '-' . $generation;
    }
}
