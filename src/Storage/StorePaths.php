<?php

declare(strict_types=1);

namespace Nucleo\Storage;

defined('ABSPATH') || exit;

use Nucleo\Support\Filesystem;

/**
 * Passo di Activation: fino alla 0.4.0 lo store stava in wp-content/cache/nucleo. install() sposta la generazione
 * del blog corrente in ArtifactStoreFactory::base(). Il frontend non carica questa classe.
 */
final class StorePaths
{
    /** Passo di Activation: all'aggiornamento sposta la generazione del blog dalla cartella vecchia, se c'è. */
    public static function install(): void
    {
        $legacy = trailingslashit(WP_CONTENT_DIR) . 'cache/nucleo';
        $generation = ArtifactStoreFactory::generation();
        if ($generation === '' || !is_dir($legacy)) {
            return;
        }
        $from = ArtifactStoreFactory::generationDir($legacy, $generation);
        $to = ArtifactStoreFactory::generationDir(ArtifactStoreFactory::base(), $generation);
        if (!is_dir($from) || self::hasArtifacts($to) || !wp_mkdir_p(dirname($to))) {
            return;
        }
        $fs = Filesystem::direct();
        // Lo store di questa richiesta può aver già creato la cartella nuova, vuota.
        if (is_dir($to)) {
            $fs->delete($to, true);
        }
        // Se lo spostamento non riesce, la nuova cartella resta vuota e StoreMaintenance::maintain() ricostruisce gli artefatti.
        $fs->move($from, $to);
        \Nucleo\Services::reset();
    }

    /** True se la cartella contiene almeno un artefatto (index.php escluso). */
    private static function hasArtifacts(string $dir): bool
    {
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            if (basename($file) !== 'index.php') {
                return true;
            }
        }

        return false;
    }
}
