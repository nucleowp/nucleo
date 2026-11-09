<?php

declare(strict_types=1);

namespace Nucleo\Support;

/**
 * Plugin di minify e unione CSS e JS che rigenerano file diversi per pagina
 * e annullano le varianti CSS per template di Nucleo.
 */
final class Conflicts
{
    /**
     * @param list<string>|null $active plugin attivi; null legge active_plugins
     * @param (callable(string):mixed)|null $option lettura opzioni; null usa get_option
     * @return list<array{plugin:string,issue:string,fix:string}>
     */
    public static function detect(?array $active = null, ?callable $option = null): array
    {
        $active ??= (array) get_option('active_plugins', []);
        $option ??= static fn (string $name): mixed => get_option($name);
        $found = [];

        if (
            in_array('autoptimize/autoptimize.php', $active, true)
            && $option('autoptimize_css_aggregate') === 'on'
        ) {
            $found[] = self::row(
                'autoptimize',
                'Autoptimize',
                __('Disable "Aggregate CSS" in Autoptimize or exclude the folder wp-content/uploads/nucleo/.', 'nucleo')
            );
        }

        if (
            in_array('autoptimize/autoptimize.php', $active, true)
            && ($option('autoptimize_js_aggregate') === 'on' || $option('autoptimize_js') === 'on')
        ) {
            $found[] = self::row(
                'autoptimize',
                'Autoptimize',
                __('Disable "Aggregate JS" and "Optimize JS" in Autoptimize or exclude the folder wp-content/uploads/nucleo/.', 'nucleo'),
                true
            );
        }

        if (in_array('wp-rocket/wp-rocket.php', $active, true)) {
            $settings = $option('wp_rocket_settings');
            if (is_array($settings) && !empty($settings['minify_concatenate_css'])) {
                $found[] = self::row(
                    'wp-rocket',
                    'WP Rocket',
                    __('Disable "Concatenate CSS files" in WP Rocket or exclude the folder wp-content/uploads/nucleo/.', 'nucleo')
                );
            }
            if (
                is_array($settings)
                && (!empty($settings['minify_concatenate_js']) || !empty($settings['minify_js']) || !empty($settings['defer_all_js']))
            ) {
                $found[] = self::row(
                    'wp-rocket',
                    'WP Rocket',
                    __('Disable "Concatenate JS files", "Minify JS" and "Load JavaScript deferred" in WP Rocket or exclude the folder wp-content/uploads/nucleo/.', 'nucleo'),
                    true
                );
            }
        }

        if (in_array('litespeed-cache/litespeed-cache.php', $active, true)) {
            if ((bool) $option('litespeed.conf.optm-css_comb')) {
                $found[] = self::row(
                    'litespeed',
                    'LiteSpeed Cache',
                    __('Disable "Combine CSS" in LiteSpeed Cache or exclude the folder wp-content/uploads/nucleo/.', 'nucleo')
                );
            }
            if (
                (bool) $option('litespeed.conf.optm-js_comb')
                || (bool) $option('litespeed.conf.optm-js_min')
                || (bool) $option('litespeed.conf.optm-js_defer')
            ) {
                $found[] = self::row(
                    'litespeed',
                    'LiteSpeed Cache',
                    __('Disable "Combine JS", "Minify JS" and "Load JS deferred" in LiteSpeed Cache or exclude the folder wp-content/uploads/nucleo/.', 'nucleo'),
                    true
                );
            }
        }

        if (in_array('w3-total-cache/w3-total-cache.php', $active, true)) {
            $found[] = [
                'plugin' => 'w3tc',
                'issue' => __('If CSS merging in W3 Total Cache is enabled, it regenerates different files per page and cancels the per-template CSS variants of Nucleo; the setting lives in a file, so it must be checked by hand.', 'nucleo'),
                'fix' => __('Check the CSS minify settings of W3 Total Cache and disable merging, or exclude the folder wp-content/uploads/nucleo/.', 'nucleo'),
            ];
            $found[] = [
                'plugin' => 'w3tc',
                'issue' => __('If JS merging, minification or deferral in W3 Total Cache is enabled, it can change the scripts of Nucleo blocks on every page; the setting lives in a file, so it must be checked by hand.', 'nucleo'),
                'fix' => __('Check the JS minify settings of W3 Total Cache and disable merging and deferral, or exclude the folder wp-content/uploads/nucleo/.', 'nucleo'),
            ];
        }

        if (in_array('fast-velocity-minify/fvm.php', $active, true)) {
            $found[] = self::row(
                'fvm',
                'Fast Velocity Minify',
                __('Disable CSS combining in Fast Velocity Minify or exclude the folder wp-content/uploads/nucleo/.', 'nucleo')
            );
            $found[] = self::row(
                'fvm',
                'Fast Velocity Minify',
                __('Disable JS combining in Fast Velocity Minify or exclude the folder wp-content/uploads/nucleo/.', 'nucleo'),
                true
            );
        }

        return $found;
    }

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'renderNotice']);
    }

    public static function renderNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $found = self::detect();
        if ($found === []) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Nucleo: cache conflicts detected', 'nucleo') . '</strong></p><ul>';
        foreach ($found as $row) {
            echo '<li>' . esc_html($row['issue']) . ' ' . esc_html($row['fix']) . '</li>';
        }
        echo '</ul></div>';
    }

    /** @return array{plugin:string,issue:string,fix:string} */
    private static function row(string $plugin, string $label, string $fix, bool $js = false): array
    {
        $issue = $js
            ? sprintf(/* translators: %s: name of the cache plugin */ __('JS merging or deferral in %s can change the scripts of Nucleo blocks, such as the gallery script, on every page.', 'nucleo'), $label)
            : sprintf(/* translators: %s: name of the cache plugin */ __('CSS merging in %s regenerates different files per page and cancels the per-template CSS variants of Nucleo.', 'nucleo'), $label);

        return [
            'plugin' => $plugin,
            'issue' => $issue,
            'fix' => $fix,
        ];
    }
}
