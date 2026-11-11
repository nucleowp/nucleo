<?php

declare(strict_types=1);

namespace Nucleo\Runtime;

defined('ABSPATH') || exit;

/** Hook del frontend: solo due callback, il resto si carica quando serve. */
final class Bootstrap
{
    public static function register(): void
    {
        add_action('wp', [Frontend::class, 'boot'], 1);
        // Priorita' 9: prima di wpautop (10), che viene comunque rimosso per i post Nucleo.
        add_filter('the_content', [Frontend::class, 'filterContent'], 9);
    }
}
