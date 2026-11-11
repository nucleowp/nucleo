<?php

declare(strict_types=1);

namespace Nucleo\Admin;

defined('ABSPATH') || exit;

/**
 * Fonte unica di menu, schede e opzioni dell'area Nucleo (docs/ui/design/31-dipendenze-contratto.dc.html).
 * Menu, dati di avvio (nav) e pagine React leggono queste regole; Access le risolve.
 *
 * Una regola: cap (capability), edition (free|pro), plugins (basta uno attivo), missing (esito se mancano:
 * hidden|explain), feature (chiave di Access::env()['features']), write (capability per scrivere, se no readonly).
 */
final class Registry
{
    /**
     * Voci del menu, nell'ordine di visualizzazione (design D2, Modello dei contenuti dopo Kit).
     *
     * Ogni voce ha 'icon': nome del symbol in assets/admin/icons.svg (nc-*).
     *
     * @return array<string, array{label: string, cap: string, edition: string, icon: string, freeLabel?: string, write?: string, tabs?: array<string, array<string, mixed>>, options?: array<string, array<string, mixed>>}>
     */
    public static function items(): array
    {
        return [
            'nucleo' => ['label' => __('Overview', 'nucleo'), 'cap' => 'edit_posts', 'edition' => 'free', 'icon' => 'nc-panoramica'],
            'nucleo-documents' => ['label' => __('Pages', 'nucleo'), 'cap' => 'edit_posts', 'edition' => 'free', 'icon' => 'nc-editor'],
            'nucleo-layouts' => [
                'label' => __('Layouts', 'nucleo'), 'cap' => 'edit_theme_options', 'edition' => 'free', 'icon' => 'nc-layout',
                'options' => [
                    'language' => ['plugins' => ['polylang', 'wpml'], 'missing' => 'explain'],
                ],
            ],
            'nucleo-sections' => ['label' => __('Global sections', 'nucleo'), 'cap' => 'edit_theme_options', 'edition' => 'free', 'icon' => 'nc-sezioni'],
            'nucleo-design' => ['label' => __('Design system', 'nucleo'), 'cap' => 'edit_theme_options', 'edition' => 'free', 'icon' => 'nc-design'],
            // Le voci Pro non esistono nel pacchetto per WordPress.org: lì nessuna voce resta bloccata.
            'nucleo-content' => ['label' => __('Content model', 'nucleo'), 'cap' => 'manage_options', 'edition' => 'free', 'icon' => 'nc-traduzioni'],
            'nucleo-quality' => [
                'label' => __('Quality and SEO', 'nucleo'), 'cap' => 'edit_posts', 'edition' => 'free', 'write' => 'edit_others_posts', 'icon' => 'nc-qualita',
                'tabs' => [
                    'issues' => ['cap' => 'edit_others_posts'],
                    'pages' => [],
                    'overrides' => ['cap' => 'edit_others_posts'],
                    'rules' => ['cap' => 'edit_theme_options'],
                ],
            ],
            'nucleo-agents' => ['label' => __('AI agents', 'nucleo'), 'cap' => 'manage_options', 'edition' => 'free', 'icon' => 'nc-agenti'],
            'nucleo-activity' => ['label' => __('AI activity', 'nucleo'), 'cap' => 'edit_posts', 'edition' => 'free', 'write' => 'edit_others_posts', 'icon' => 'nc-revisioni'],
            'nucleo-tools' => ['label' => __('Tools', 'nucleo'), 'cap' => 'manage_options', 'edition' => 'free', 'icon' => 'nc-strumenti'],
            'nucleo-settings' => [
                'label' => __('Settings', 'nucleo'), 'cap' => 'manage_options', 'edition' => 'free', 'icon' => 'nc-impostazioni',
                'tabs' => [
                    'editor' => [],
                    'performance' => [],
                    'captcha' => [],
                    'integrations' => [],
                    'agents' => [],
                ],
            ],
            // Assistenza: per ogni utente che vede il menu Nucleo, in Free e in Pro.
            'nucleo-support' => ['label' => __('Support', 'nucleo'), 'cap' => 'edit_posts', 'edition' => 'free', 'icon' => 'nc-assistenza'],
        ];
    }
}
