<?php

declare(strict_types=1);

namespace Nucleo\Support;

defined('ABSPATH') || exit;

/**
 * Accesso diretto al disco per la cache di Nucleo, con l'API di WordPress.
 * WP_Filesystem() può chiedere credenziali FTP: la classe Direct scrive sempre con i permessi di PHP.
 * Solo sui percorsi di scrittura: il frontend non carica i file di wp-admin.
 */
final class Filesystem
{
    private static ?\WP_Filesystem_Direct $direct = null;

    public static function direct(): \WP_Filesystem_Direct
    {
        if (self::$direct === null) {
            if (!class_exists('WP_Filesystem_Direct', false)) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            }
            self::$direct = new \WP_Filesystem_Direct(null);
        }

        return self::$direct;
    }
}
