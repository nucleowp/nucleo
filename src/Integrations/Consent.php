<?php

declare(strict_types=1);

namespace Nucleo\Integrations;

defined('ABSPATH') || exit;

/**
 * Rileva il plugin di consenso cookie e restituisce gli attributi per sbloccare un iframe.
 */
final class Consent
{
    private const IUBENDA_PURPOSES = ['marketing' => '5', 'statistics' => '4', 'preferences' => '2'];
    private const COOKIEYES_CATEGORIES = ['marketing' => 'advertisement', 'statistics' => 'analytics', 'preferences' => 'functional'];

    /** Rilevato dal plugin attivo, sovrascrivibile con il filtro nucleo/consent_plugin. */
    public static function plugin(): ?string
    {
        $detected = match (true) {
            defined('CYBOT_COOKIEBOT_VERSION') || class_exists('Cookiebot_WP') => 'cookiebot',
            class_exists('iubenda') => 'iubenda',
            defined('CMPLZ_VERSION') => 'complianz',
            defined('CLI_VERSION') || defined('CKY_APP_VERSION') => 'cookieyes',
            default => null,
        };

        // Outside WordPress (pure compiler tests) there is no consent plugin.
        $plugin = function_exists('apply_filters') ? apply_filters('nucleo/consent_plugin', $detected) : $detected;

        return is_string($plugin) && $plugin !== '' ? $plugin : null;
    }

    /**
     * @return array<string, string> attributi da applicare al contenitore dell'iframe
     */
    public static function attributes(string $category): array
    {
        return match (self::plugin()) {
            'cookiebot' => ['data-cookieconsent' => $category],
            'iubenda' => self::iubenda($category),
            'complianz' => ['data-category' => $category, 'data-service' => 'youtube'],
            'cookieyes' => isset(self::COOKIEYES_CATEGORIES[$category])
                ? ['data-cookieyes' => 'cookieyes-' . self::COOKIEYES_CATEGORIES[$category]]
                : [],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private static function iubenda(string $category): array
    {
        $attributes = ['class' => '_iub_cs_activate'];
        if (isset(self::IUBENDA_PURPOSES[$category])) {
            $attributes['data-iub-purposes'] = self::IUBENDA_PURPOSES[$category];
        }

        return $attributes;
    }
}
