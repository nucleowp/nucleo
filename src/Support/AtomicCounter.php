<?php

declare(strict_types=1);

namespace Nucleo\Support;

/**
 * Contatore atomico con scadenza, per limiti di frequenza e tentativi.
 * Con una object cache persistente usa wp_cache_add + wp_cache_incr (atomici su Redis e Memcached);
 * altrimenti una riga di wp_options incrementata con un solo UPDATE: richieste in parallelo non leggono lo stesso valore.
 */
final class AtomicCounter
{
    private const GROUP = 'nucleo_counter';
    private const PREFIX = 'nucleo_ctr_';

    /** Incrementa e restituisce il nuovo valore. $ttl in secondi, dal primo incremento. */
    public static function incr(string $key, int $ttl): int
    {
        $id = md5($key);
        if (wp_using_ext_object_cache()) {
            wp_cache_add($id, 0, self::GROUP, $ttl);
            $value = wp_cache_incr($id, 1, self::GROUP);
            if (is_int($value)) {
                return $value;
            }
        }

        global $wpdb;
        $now = time();
        $name = self::PREFIX . $id;
        // La riga contiene "scadenza:conteggio". INSERT IGNORE la crea una volta; l'UPDATE condizionale azzera una riga scaduta.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            $name,
            ($now + $ttl) . ':0'
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = CONCAT(%d, ':0') WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) <= %d",
            $now + $ttl,
            $name,
            $now
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = CONCAT(SUBSTRING_INDEX(option_value, ':', 1), ':', CAST(SUBSTRING_INDEX(option_value, ':', -1) AS UNSIGNED) + 1) WHERE option_name = %s",
            $name
        ));
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if (wp_rand(1, 100) === 1) {
            self::purge($now);
        }

        return is_string($value) ? (int) substr($value, (int) strpos($value, ':') + 1) : 1;
    }

    /** Cancella il contatore (per esempio un ticket verificato). */
    public static function delete(string $key): void
    {
        $id = md5($key);
        if (wp_using_ext_object_cache()) {
            wp_cache_delete($id, self::GROUP);
        }
        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name' => self::PREFIX . $id]);
    }

    /** Cancella le righe scadute: le chiavi dei limiti sono una per IP e si accumulerebbero. */
    private static function purge(int $now): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) <= %d",
            $wpdb->esc_like(self::PREFIX) . '%',
            $now
        ));
    }
}
