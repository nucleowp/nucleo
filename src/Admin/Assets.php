<?php

declare(strict_types=1);

namespace Nucleo\Admin;

defined('ABSPATH') || exit;

/**
 * Risorse condivise dell'area Nucleo e della schermata di modifica, registrate una sola volta per richiesta.
 */
final class Assets
{
    /**
     * Registra il foglio dei token (tokens.css) con la variabile dello sfondo. Non registra di nuovo se già fatto.
     */
    public static function registerTokens(string $base): void
    {
        if (wp_style_is('nucleo-admin-tokens', 'registered')) {
            return;
        }

        wp_register_style('nucleo-admin-tokens', $base . 'assets/admin/tokens.css', [], Editor::assetVersion('assets/admin/tokens.css'));
        wp_add_inline_style(
            'nucleo-admin-tokens',
            '.nucleo-admin,.nucleo-admin-portal{--nc-bg-image:url("' . esc_url($base . 'assets/admin/bg.webp') . '")}'
        );
    }

    /**
     * Registra tokens.css e admin.css (componenti nc-*): una sola fonte per pagine, editor e sovrapposizioni.
     */
    public static function registerComponents(string $base): void
    {
        self::registerTokens($base);
        if (wp_style_is('nucleo-admin', 'registered')) {
            return;
        }

        wp_register_style('nucleo-admin', $base . 'assets/admin/admin.css', ['nucleo-admin-tokens', 'wp-components'], Editor::assetVersion('assets/admin/admin.css'));
    }

    /**
     * Registra tree.js e ui.js (window.nucleoUI): componenti React condivisi tra pagine ed editor.
     */
    public static function registerUi(string $base): void
    {
        if (!wp_script_is('nucleo-editor-tree', 'registered')) {
            wp_register_script('nucleo-editor-tree', $base . 'assets/editor/tree.js', [], Editor::assetVersion('assets/editor/tree.js'), true);
        }
        if (wp_script_is('nucleo-ui', 'registered')) {
            return;
        }

        wp_register_script(
            'nucleo-ui',
            $base . 'assets/admin/ui.js',
            ['wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'nucleo-editor-tree'],
            Editor::assetVersion('assets/admin/ui.js'),
            true
        );
    }
}
