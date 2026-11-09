<?php

declare(strict_types=1);

namespace Nucleo\Layout;

use Nucleo\Content\ModelRegistrar;

/** Tipi di contenuto dei layout e delle sezioni globali. Registrazione idempotente. */
final class PostTypes
{
    public const LAYOUT = 'nucleo_layout';
    public const SECTION = 'nucleo_section';

    private static bool $hooked = false;

    /** Registra subito se init è già partito, altrimenti alla prossima init. */
    public static function register(): void
    {
        if (did_action('init')) {
            self::registerTypes();

            return;
        }
        if (!self::$hooked) {
            self::$hooked = true;
            add_action('init', [self::class, 'registerTypes']);
        }
    }

    public static function registerTypes(): void
    {
        $capabilities = [
            'create_posts' => 'edit_theme_options',
            'edit_posts' => 'edit_theme_options',
            'delete_posts' => 'edit_theme_options',
        ];
        $types = [
            self::LAYOUT => 'Layout Nucleo',
            self::SECTION => __('Global section', 'nucleo'),
        ];

        foreach ($types as $type => $label) {
            if (post_type_exists($type)) {
                continue;
            }
            register_post_type($type, [
                'label' => $label,
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => false,
                'supports' => ['title'],
                'capability_type' => 'page',
                'map_meta_cap' => true,
                'capabilities' => $capabilities,
            ]);
        }

        ModelRegistrar::registerAll();
    }
}
