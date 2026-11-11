<?php

/**
 * Plugin Name:       Nucleo
 * Description:       AI-first block builder: JSON documents compiled at save time, zero runtime cost.
 * Version:           0.4.4
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * License:           GPL-2.0-or-later
 * Text Domain:       nucleo
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('NUCLEO_VERSION', '0.4.4');
define('NUCLEO_FILE', __FILE__);
define('NUCLEO_DIR', __DIR__ . '/');
define('NUCLEO_MIN_PHP', '8.2');
define('NUCLEO_MIN_WP', '6.9');

if (version_compare(PHP_VERSION, NUCLEO_MIN_PHP, '<')) {
    return;
}

require __DIR__ . '/src/autoload.php';
// Third-party libraries load on demand through Nucleo\Support\Vendor::load().

// Activation happens in admin or WP-CLI only: no callback on frontend requests.
if (is_admin() || (defined('WP_CLI') && WP_CLI)) {
    register_activation_hook(__FILE__, [\Nucleo\Support\Activation::class, 'activate']);
    // Registro delle email di sviluppo: niente codici né dati dei moduli restano dopo la disattivazione.
    register_deactivation_hook(__FILE__, [\Nucleo\Forms\DevMail::class, 'deactivate']);
}
\Nucleo\Plugin::boot();
