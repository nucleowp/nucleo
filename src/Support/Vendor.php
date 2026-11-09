<?php

declare(strict_types=1);

namespace Nucleo\Support;

/**
 * Loads third-party libraries on demand, only in background jobs (purger, audits).
 * Frontend requests never call this, so they never pay for the vendor autoloader.
 */
final class Vendor
{
    public static function load(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;
        // Already autoloadable (test bootstrap, or another plugin): nothing to register.
        if (class_exists(\Sabberworm\CSS\Parser::class)) {
            return;
        }
        $dir = dirname(__DIR__, 2);
        // Release builds ship prefixed libraries; development uses Composer's vendor.
        foreach (['/vendor-prefixed/autoload.php', '/vendor/autoload.php'] as $file) {
            if (is_file($dir . $file)) {
                require_once $dir . $file;
                return;
            }
        }
    }
}
