<?php

declare(strict_types=1);

namespace Nucleo\Support;

defined('ABSPATH') || exit;

/**
 * Fino alla 0.4.0 i transient di raccolte e codici OTP usavano il prefisso nc_. Ora usano nucleo_.
 * All'aggiornamento questo passo cancella quelli vecchi, che altrimenti scadrebbero da soli entro 12 ore.
 */
final class LegacyTransients
{
    /** Prefissi dei transient non più usati. */
    private const PREFIXES = ['nc_coll_', 'nc_otp_'];

    public static function install(): void
    {
        global $wpdb;
        foreach (self::PREFIXES as $prefix) {
            foreach (['_transient_', '_transient_timeout_'] as $kind) {
                $like = $wpdb->esc_like($kind . $prefix) . '%';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- pulizia una tantum all'aggiornamento: WordPress non ha una funzione per cancellare i transient per prefisso.
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
            }
        }
    }
}
