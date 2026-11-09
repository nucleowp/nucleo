<?php

declare(strict_types=1);

namespace Nucleo\Admin;

/**
 * Menu Nucleo di primo livello: una voce per pagina, con il contatore della qualità.
 * Le pagine React le monta AdminApp; Agenti e Licenza restano pagine PHP.
 */
final class Menu
{
    public const BADGE_TRANSIENT = 'nucleo_quality_badge';

    private const TOP = 'nucleo';
    private const POSITION = 61;

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addPages'], 9);
        add_action('admin_enqueue_scripts', [self::class, 'menuStyles']);
    }

    /**
     * Le pagine del menu, nell'ordine di Registry: [slug, titolo, capability, pro].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function pages(): array
    {
        $pages = [];
        foreach (Registry::items() as $slug => $rule) {
            $pages[] = [$slug, $rule['label'], $rule['cap'], $rule['edition'] === 'pro'];
        }

        return $pages;
    }

    public static function addPages(): void
    {
        add_menu_page(
            __('Nucleo AI', 'nucleo'),
            __('Nucleo AI', 'nucleo'),
            'edit_posts',
            self::TOP,
            [AdminApp::class, 'render'],
            self::icon(),
            self::POSITION
        );

        $quality = (int) get_transient(self::BADGE_TRANSIENT);
        $caps = array_map(static fn (array $rule): string => $rule['cap'], Registry::items());
        // Access::nav() ha già tolto le voci nascoste: WordPress mostra solo quelle consentite all'utente.
        foreach (Access::nav() as $item) {
            $slug = $item['slug'];
            $label = $item['label'];
            if ($slug === 'nucleo-quality' && $quality > 0 && current_user_can('edit_others_posts')) {
                $label .= ' <span class="nc-menu-badge">' . $quality . '</span>';
            }
            add_submenu_page(self::TOP, $item['label'], $label, $caps[$slug], $slug, self::callback($slug));
        }
    }

    /**
     * Stili delle etichette nel menu (contatore della qualità) e delle notice Nucleo fuori dal wrapper:
     * assets/admin/menu.css su ogni schermata di amministrazione, perché il menu e le notice compaiono ovunque.
     */
    public static function menuStyles(): void
    {
        $file = 'assets/admin/menu.css';
        wp_enqueue_style('nucleo-admin-menu', plugin_dir_url(NUCLEO_FILE) . $file, [], Editor::assetVersion($file));
    }

    private static function callback(string $slug): callable
    {
        if ($slug === 'nucleo-agents') {
            return [AgentsPage::class, 'render'];
        }

        return [AdminApp::class, 'render'];
    }

    /**
     * Icona monocromatica 20x20 per il menu, in data URI: una A maiuscola fusa con una i minuscola.
     * La diagonale della A scende fino alla base dell'asta della i. Il punto della i è quadrato,
     * allineato in alto con la A e separato dall'asta da un taglio di 1 px.
     */
    private static function icon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20">'
            . '<path fill="currentColor" d="M4.8 3H7V17H4.8Z M4.8 3H7.2L15.2 17H12.9Z M12.9 6.3H15.2V17H12.9Z'
            . ' M5.9 10.8H11V12.7H5.9Z M12.9 3H15.2V5.3H12.9Z"/></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
