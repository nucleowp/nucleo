<?php

declare(strict_types=1);

namespace Nucleo\Admin;

use Nucleo\OAuth\Authorize;
use Nucleo\Support\Optional;

/**
 * Shell React dell'area Nucleo: monta la pagina della voce in #nucleo-admin-app e passa a window.nucleoAdmin i dati di avvio
 * (docs/ui/admin-ui-contract.md).
 */
final class AdminApp
{
    private const BREAKPOINTS = ['md' => 768, 'lg' => 1024, 'xl' => 1920];
    private const EDITOR_SLUG = 'nucleo-editor';
    private const AGENTS = 'nucleo-agents';
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function render(): void
    {
        $page = self::currentPage();
        $entry = self::entry($page);
        if ($entry === null || !current_user_can($entry[2])) {
            wp_die(esc_html__('You do not have permission to access this page.', 'nucleo'), '', ['response' => 403]);
        }

        echo '<div class="wrap nucleo-admin">' . Icons::sprite() . '<div id="nucleo-admin-app" data-page="' . esc_attr($page) . '"></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statico del plugin, nome icona validato da Icons.
    }

    /**
     * Dati di avvio per window.nucleoAdmin.
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'page' => self::currentPage(),
            'version' => NUCLEO_VERSION,
            // Dati da incollare nella richiesta di assistenza (pagina Assistenza).
            'system' => [
                'wordpress' => get_bloginfo('version'),
                'php' => PHP_VERSION,
                'theme' => wp_get_theme()->get('Name') . ' ' . wp_get_theme()->get('Version'),
                'locale' => get_locale(),
            ],
            'can' => [
                'read' => current_user_can('edit_posts'),
                'write' => current_user_can('edit_posts'),
                'publish' => current_user_can('publish_pages'),
                'design' => current_user_can('edit_theme_options'),
                'admin' => current_user_can('manage_options'),
                'installPlugins' => current_user_can('install_plugins'),
            ],
            'urls' => [
                'editor' => admin_url('admin.php?page=' . self::EDITOR_SLUG . '&post='),
                'mcp' => rest_url('nucleo/v1/mcp'),
                'adminPost' => admin_url('admin-post.php'),
                'admin' => admin_url(),
            ],
            'links' => Links::forAdmin(),
            'breakpoints' => self::BREAKPOINTS,
            'readonly' => Editor::readonlyAbilities(),
            'i18n' => [
                'locale' => get_user_locale(),
                'languages' => self::languages(),
            ],
            'pages' => array_map(static fn (array $p): array => [
                'slug' => $p[0],
                'title' => $p[1],
                'url' => admin_url('admin.php?page=' . $p[0]),
                'pro' => $p[3],
            ], Menu::pages()),
            'env' => Access::env(),
            'nav' => Access::nav(),
        ];
    }

    /** Stili su ogni pagina del menu e sulla schermata di consenso OAuth; script solo sulle pagine React. */
    public static function enqueue(string $hook): void
    {
        $page = self::currentPage();
        $isConsent = $page === Authorize::SLUG;
        if (!$isConsent && self::entry($page) === null) {
            return;
        }

        $root = dirname(__DIR__, 2);
        $base = plugin_dir_url($root . '/nucleo.php');

        Assets::registerComponents($base);
        wp_enqueue_style('nucleo-admin');

        if ($isConsent || $page === self::AGENTS) {
            return;
        }

        Assets::registerUi($base);
        $handles = ['nucleo-ui'];
        // Dialoghi condivisi tra le pagine dei documenti (nuovo documento, traduzione).
        wp_register_script('nucleo-admin-shared', $base . 'assets/admin/shared.js', ['nucleo-ui'], Editor::assetVersion('assets/admin/shared.js'), true);
        $handles[] = 'nucleo-admin-shared';
        // Differenze tra revisioni (window.nucleoDocDiff): dipendenza di Attività AI.
        wp_register_script('nucleo-doc-diff', $base . 'assets/shared/doc-diff.js', [], Editor::assetVersion('assets/shared/doc-diff.js'), true);
        wp_set_script_translations('nucleo-doc-diff', 'nucleo', $root . '/languages');

        $key = $page === 'nucleo' ? 'overview' : substr($page, strlen('nucleo-'));
        // Pro edition only: CSS measurement in the browser for the Performance page.
        $optimizer = 'assets/admin/css-optimizer.js';
        if ($key === 'performance' && is_file($root . '/' . $optimizer)) {
            wp_register_script('nucleo-css-optimizer', $base . $optimizer, [], Editor::assetVersion($optimizer), true);
            $handles[] = 'nucleo-css-optimizer';
        }
        // Pulsanti "Copia" (data-nc-copy) della pagina Strumenti: listener delegato sul documento.
        if ($key === 'tools') {
            wp_register_script('nucleo-admin-copy', $base . 'assets/admin/copy.js', [], Editor::assetVersion('assets/admin/copy.js'), true);
            $handles[] = 'nucleo-admin-copy';
        }
        // La Panoramica riusa le etichette delle azioni di Attività AI (window.nucleoActivity).
        if ($key === 'overview') {
            $activity = 'assets/admin/pages/activity.js';
            wp_register_script('nucleo-page-activity', $base . $activity, ['nucleo-ui', 'nucleo-admin-shared', 'nucleo-doc-diff'], Editor::assetVersion($activity), true);
            $handles[] = 'nucleo-page-activity';
        }
        $pageFile = 'assets/admin/pages/' . $key . '.js';
        if (is_file($root . '/' . $pageFile)) {
            $pageDeps = $key === 'activity' ? ['nucleo-ui', 'nucleo-admin-shared', 'nucleo-doc-diff'] : ['nucleo-ui', 'nucleo-admin-shared'];
            wp_register_script('nucleo-page-' . $key, $base . $pageFile, $pageDeps, Editor::assetVersion($pageFile), true);
            $handles[] = 'nucleo-page-' . $key;
        }
        // Stili propri della pagina, accanto al suo script: solo var(--nc-*), come admin.css.
        $pageCss = 'assets/admin/pages/' . $key . '.css';
        if (is_file($root . '/' . $pageCss)) {
            wp_enqueue_style('nucleo-page-' . $key, $base . $pageCss, ['nucleo-admin'], Editor::assetVersion($pageCss));
        }

        wp_register_script(
            'nucleo-admin-app',
            $base . 'assets/admin/app.js',
            $handles,
            Editor::assetVersion('assets/admin/app.js'),
            true
        );
        $handles[] = 'nucleo-admin-app';
        foreach ($handles as $handle) {
            wp_set_script_translations($handle, 'nucleo', $root . '/languages');
        }

        wp_enqueue_script('nucleo-admin-app');
        // Prima del primo script (nucleo-ui): le pagine leggono la configurazione già al caricamento.
        wp_add_inline_script(
            'nucleo-ui',
            'window.nucleoAdmin = ' . (wp_json_encode(self::config(), self::JSON_FLAGS) ?: '{}') . ';',
            'before'
        );
    }

    /** Slug della pagina in corso, da $GLOBALS['plugin_page'] impostata da WordPress. */
    private static function currentPage(): string
    {
        $page = $GLOBALS['plugin_page'] ?? '';

        return is_string($page) ? $page : '';
    }

    /** @return array{0: string, 1: string, 2: string, 3: bool}|null */
    private static function entry(string $page): ?array
    {
        foreach (Menu::pages() as $entry) {
            if ($entry[0] === $page) {
                return $entry;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function languages(): array
    {
        $found = Optional::call('Nucleo\\Integrations\\Multilingual', 'languages');
        $languages = apply_filters('nucleo/languages', is_array($found) ? $found : []);

        return is_array($languages) ? array_values(array_map('strval', $languages)) : [];
    }
}
