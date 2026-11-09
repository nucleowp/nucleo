<?php

declare(strict_types=1);

namespace Nucleo\Storage;

/**
 * Lock di un documento per la durata di un salvataggio: due scritture sulla stessa base non passano entrambe.
 * Usa GET_LOCK di MySQL, legato alla connessione: si libera anche se PHP si interrompe.
 * Rientrante nella stessa richiesta (un salvataggio che ne chiama un altro sullo stesso post).
 */
final class DocumentLock
{
    /** @var array<int,int> post => profondità */
    private static array $held = [];

    /** Secondi di attesa del lock; il filtro nucleo/document_lock_timeout li cambia (0: nessuna attesa). */
    public static function timeout(): int
    {
        return max(0, (int) apply_filters('nucleo/document_lock_timeout', 10));
    }

    public static function acquire(int $postId): bool
    {
        if (isset(self::$held[$postId])) {
            ++self::$held[$postId];

            return true;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock di MySQL, non un dato da mettere in cache.
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::name($postId), self::timeout()));
        if ((string) $got !== '1') {
            return false;
        }
        self::$held[$postId] = 1;

        return true;
    }

    public static function release(int $postId): void
    {
        if (!isset(self::$held[$postId])) {
            return;
        }
        if (--self::$held[$postId] > 0) {
            return;
        }
        unset(self::$held[$postId]);
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock di MySQL.
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::name($postId)));
    }

    /** Nome del lock, unico per database e post (MySQL limita i nomi a 64 caratteri). */
    public static function name(int $postId): string
    {
        global $wpdb;

        return 'nucleo_doc_' . substr(md5((string) $wpdb->dbname . '|' . $wpdb->prefix), 0, 12) . '_' . $postId;
    }
}
