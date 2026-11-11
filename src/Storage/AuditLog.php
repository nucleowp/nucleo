<?php

declare(strict_types=1);

namespace Nucleo\Storage;

defined('ABSPATH') || exit;

use Nucleo\OAuth\CurrentToken;

/**
 * Registro delle scritture eseguite dalle ability: chi, quando, quale ability, esito.
 * Conserva le ultime MAX_ROWS righe; la pulizia avviene ogni PRUNE_EVERY inserimenti.
 */
final class AuditLog
{
    private const MAX_ROWS = 10000;
    private const PRUNE_EVERY = 100;
    private const COUNTER_OPTION = 'nucleo_audit_inserts';

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            time int(10) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            client_id varchar(191) DEFAULT NULL,
            ability varchar(64) NOT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            ok tinyint(1) NOT NULL DEFAULT 0,
            summary varchar(255) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY time (time)
        ) $charset;");
    }

    public static function record(string $ability, int $postId, bool $ok, string $summary): void
    {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'time' => time(),
            'user_id' => get_current_user_id(),
            'client_id' => CurrentToken::clientId(),
            'ability' => substr($ability, 0, 64),
            'post_id' => $postId,
            'ok' => $ok ? 1 : 0,
            'summary' => substr($summary, 0, 255),
        ]);

        $inserts = (int) get_option(self::COUNTER_OPTION, 0) + 1;
        update_option(self::COUNTER_OPTION, $inserts, false);
        if ($inserts % self::PRUNE_EVERY === 0) {
            self::prune();
        }
    }

    /** @return list<array<string,mixed>> Dalla più recente. */
    public static function recent(int $limit = 50): array
    {
        global $wpdb;
        $limit = max(1, min($limit, 1000));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, time, user_id, client_id, ability, post_id, ok, summary FROM %i ORDER BY id DESC LIMIT %d',
            self::table(),
            $limit,
        ), ARRAY_A);

        return array_values(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'time' => (int) $row['time'],
            'user_id' => (int) $row['user_id'],
            'client_id' => $row['client_id'] === null ? null : (string) $row['client_id'],
            'ability' => (string) $row['ability'],
            'post_id' => (int) $row['post_id'],
            'ok' => (bool) $row['ok'],
            'summary' => (string) $row['summary'],
        ], $rows ?? []));
    }

    private static function prune(): void
    {
        global $wpdb;
        $cutoff = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d',
            self::table(),
            self::MAX_ROWS,
        ));
        if ($cutoff > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE id <= %d', self::table(), $cutoff));
        }
    }

    private static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'nucleo_audit';
    }
}
