<?php

declare(strict_types=1);

namespace Nucleo\Support;

defined('ABSPATH') || exit;

use Nucleo\Forms\FormActions;

/**
 * Fino alla 0.5.1 gli invii salvati dei moduli usavano le meta key _nc_form, _nc_data e _nc_context.
 * Ora usano il prefisso _nucleo_. All'aggiornamento questo passo rinomina le chiavi degli invii già salvati.
 */
final class LegacyFormMeta
{
    /** Chiave vecchia => chiave nuova. */
    private const KEYS = [
        '_nc_form' => FormActions::META_FORM,
        '_nc_data' => FormActions::META_DATA,
        '_nc_context' => FormActions::META_CONTEXT,
    ];

    public static function install(): void
    {
        global $wpdb;
        $renamed = 0;
        foreach (self::KEYS as $old => $new) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- rinomina una tantum all'aggiornamento: WordPress non ha una funzione per rinominare una meta key.
            $renamed += (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id SET pm.meta_key = %s WHERE pm.meta_key = %s AND p.post_type = %s",
                $new,
                $old,
                FormActions::SUBMISSION_TYPE,
            ));
        }
        // La cache dei meta conserva le chiavi vecchie: si svuota solo se la rinomina ha toccato qualcosa.
        if ($renamed > 0) {
            wp_cache_flush();
        }
    }
}
