<?php

declare(strict_types=1);

namespace Nucleo\Storage;

defined('ABSPATH') || exit;

use Nucleo\Assets\RulesPublisher;
use Nucleo\Compiler\RebuildQueue;
use Nucleo\Compiler\TokensPublisher;
use Nucleo\Content\ModelRepository;
use Nucleo\Layout\LayoutPublisher;
use Nucleo\Services;
use Nucleo\Support\Filesystem;
use RuntimeException;

/**
 * Generazione dello store e ricostruzione degli artefatti, mai sul frontend (vedi ArtifactStoreFactory).
 * Chiamata da admin_init (maintain), dal primo put senza generazione (claim) e da nucleo/cache-rebuild (rebuild).
 */
final class StoreMaintenance
{
    /** Lock della ricostruzione: valore = time() di chi l'ha preso. Scaduto dopo LOCK_TTL secondi. */
    public const LOCK_OPTION = 'nucleo_store_rebuild_lock';

    public const LOCK_TTL = 300;

    /** Nome dei file degli artefatti, come in PhpFileStore::FILE_PATTERN (privata lì). */
    private const FILE_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,63}\.[0-9a-f]{8}\.php\z/';

    /** Cartella base dello store, come in Services::store(). */
    public static function base(): string
    {
        return ArtifactStoreFactory::base();
    }

    /**
     * Garantisce la generazione del blog corrente e restituisce la sua cartella.
     * Senza generazione la crea (add_option autoload; se un'altra richiesta l'ha creata prima, usa quella).
     * Sul sito principale adotta i file della cartella base (artefatti di prima delle generazioni) se il database
     * ha dati Nucleo (hasData), altrimenti li cancella. Poi cancella le altre generazioni dello stesso blog.
     */
    public static function claim(string $base): string
    {
        $id = bin2hex(random_bytes(5));
        if (!add_option(ArtifactStoreFactory::GENERATION_OPTION, $id, '', true)) {
            wp_cache_delete('alloptions', 'options');
            $stored = get_option(ArtifactStoreFactory::GENERATION_OPTION, '');
            if (!is_string($stored) || preg_match('/\A[a-z0-9]{1,16}\z/', $stored) !== 1) {
                throw new RuntimeException('Invalid store generation');
            }
            $id = $stored;
        }

        $dir = ArtifactStoreFactory::generationDir($base, $id);
        if (!wp_mkdir_p($dir)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new RuntimeException("Cannot create artifact directory: {$dir}");
        }

        if (function_exists('is_main_site') && is_main_site()) {
            self::adoptBase($base, $dir);
        }

        $blog = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        foreach (glob(rtrim($base, '/\\') . '/' . $blog . '-*', GLOB_ONLYDIR) ?: [] as $old) {
            if ($old !== $dir) {
                self::removeDir($old);
            }
        }

        return $dir;
    }

    /**
     * admin_init: claim se manca la generazione, poi rebuild se lo store è vuoto (isEmpty). Services::reset()
     * dopo un claim, perché lo store già creato in questa richiesta legge ancora la cartella base.
     */
    public static function maintain(): void
    {
        try {
            if (ArtifactStoreFactory::generation() === '') {
                self::claim(self::base());
                Services::reset();
            }
            if (self::isEmpty()) {
                self::rebuild();
            }
        } catch (RuntimeException) {
            // Errore del filesystem in admin_init: l'admin resta utilizzabile, la richiesta successiva riprova.
        }
    }

    /**
     * Ripubblica token, regole degli asset, layout e modello dei contenuti dal database; con $documents accoda anche
     * la ricompilazione di tutti i documenti. Sotto lock: false se un'altra ricostruzione è in corso.
     */
    public static function rebuild(bool $documents = true): bool
    {
        if (!self::acquireLock()) {
            return false;
        }

        try {
            TokensPublisher::publish();
            RulesPublisher::install();
            LayoutPublisher::republish();
            ModelRepository::publish();
            if ($documents) {
                RebuildQueue::enqueueAll();
            }
        } finally {
            delete_option(self::LOCK_OPTION);
        }

        return true;
    }

    /** Store senza token o senza mappa dei layout: header, footer e token non vengono serviti. */
    public static function isEmpty(): bool
    {
        $store = Services::store();

        return $store->get('tokens') === null || $store->get('layouts') === null;
    }

    /** Il database ha contenuti Nucleo: documenti (_nucleo_doc), layout pubblicati o un modello dei contenuti. */
    public static function hasData(): bool
    {
        if (is_array(get_option(ModelRepository::OPTION, null))) {
            return true;
        }

        global $wpdb;
        $found = $wpdb->get_var(
            "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_nucleo_doc' LIMIT 1"
        );

        return $found !== null;
    }

    /** Prende il lock; se è scaduto (più vecchio di LOCK_TTL) lo riprende una volta. */
    private static function acquireLock(): bool
    {
        if (add_option(self::LOCK_OPTION, time(), '', false)) {
            return true;
        }
        $since = (int) get_option(self::LOCK_OPTION, 0);
        if (time() - $since <= self::LOCK_TTL) {
            return false;
        }
        delete_option(self::LOCK_OPTION);

        return (bool) add_option(self::LOCK_OPTION, time(), '', false);
    }

    /** File degli artefatti nella cartella base: spostati nella generazione se il database ha dati, altrimenti cancellati. */
    private static function adoptBase(string $base, string $dir): void
    {
        $keep = self::hasData();
        foreach (glob(rtrim($base, '/\\') . '/*.php') ?: [] as $file) {
            if (preg_match(self::FILE_PATTERN, basename($file)) !== 1) {
                continue;
            }
            self::forget($file);
            if (!$keep) {
                Filesystem::direct()->delete($file);
                continue;
            }
            $target = $dir . '/' . basename($file);
            if (!is_file($target)) {
                Filesystem::direct()->move($file, $target);
            }
        }
    }

    /** Cancella una generazione vecchia con i suoi file. */
    private static function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            self::forget($file);
        }
        Filesystem::direct()->delete($dir, true);
    }

    /** Invalida la cache opcode prima di spostare o cancellare un artefatto. */
    private static function forget(string $file): void
    {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }
}
