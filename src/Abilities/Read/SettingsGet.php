<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Admin\Access;
use Nucleo\Forms\Captcha;

/** nucleo/settings-get: opzioni di Nucleo senza interfaccia, primo avvio e integrazioni rilevate. */
final class SettingsGet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/settings-get';
    }

    public function args(): array
    {
        return [
            'label' => __('Get settings', 'nucleo'),
            'description' => 'Nucleo settings: editor, performance, CSS purge mode, font subsets, quality rules, '
                . 'captcha of the native forms (provider, siteKey, secretSet, threshold), onboarding state of the current user, and the detected integrations. These are the settings of the Nucleo plugin, written with nucleo/settings-set. The WordPress site options are not here: nucleo/site-describe returns the site name and the date formats, nucleo/site-options-set writes title, tagline, homepage, formats and icons.',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok', 'settings', 'integrations'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'settings' => [
                        'type' => 'object',
                        'required' => ['editor', 'performance', 'onboarding'],
                        'properties' => [
                            'editor' => [
                                'type' => 'object',
                                'properties' => [
                                    'textOnlyRoles' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                            ],
                            'performance' => [
                                'type' => 'object',
                                'properties' => [
                                    'wooFragments' => ['type' => 'string', 'enum' => ['keep', 'off']],
                                    'purgeSafelist' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                            ],
                            'assets' => [
                                'type' => 'object',
                                'properties' => [
                                    'purgeMode' => ['type' => 'string', 'enum' => ['normal', 'strict']],
                                ],
                            ],
                            'fonts' => [
                                'type' => 'object',
                                'properties' => [
                                    'subsets' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                            ],
                            'quality' => [
                                'type' => 'object',
                                'properties' => [
                                    'nonForcible' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'thinWords' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                                ],
                            ],
                            'captcha' => [
                                'type' => 'object',
                                'properties' => [
                                    'provider' => ['type' => 'string'],
                                    'siteKey' => ['type' => 'string'],
                                    'secretSet' => ['type' => 'boolean'],
                                    'threshold' => ['type' => 'number'],
                                ],
                            ],
                            'onboarding' => [
                                'type' => 'object',
                                'properties' => [
                                    'dismissed' => ['type' => 'boolean'],
                                ],
                            ],
                        ],
                    ],
                    'integrations' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'name', 'active', 'does'],
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'active' => ['type' => 'boolean'],
                                'does' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    public function execute(array $input): array
    {
        return ['ok' => true, 'settings' => self::settings(), 'integrations' => self::integrations()];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Impostazioni correnti con i default. La chiave del servizio non esce mai: si espone solo se è impostata.
     * @return array<string,mixed>
     */
    public static function settings(): array
    {
        $stored = get_option('nucleo_settings', []);
        $stored = is_array($stored) ? $stored : [];
        $roles = $stored['editor']['textOnlyRoles'] ?? [];

        $safelist = get_option('nucleo_purge_safelist', []);
        if (is_string($safelist)) {
            $purge = preg_split('/[\s,]+/', $safelist, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } else {
            $purge = is_array($safelist) ? array_values(array_filter($safelist, 'is_string')) : [];
        }

        $fonts = get_option('nucleo_font_subsets', []);
        $subsets = is_array($fonts) ? array_values(array_filter($fonts, 'is_string')) : [];

        $quality = get_option('nucleo_quality', []);
        $quality = is_array($quality) ? $quality : [];
        $nonForcible = is_array($quality['nonForcible'] ?? null) ? array_values(array_filter($quality['nonForcible'], 'is_string')) : [];
        $thinWords = is_array($quality['thinWords'] ?? null) ? array_filter($quality['thinWords'], 'is_int') : [];

        $settings = [
            'editor' => [
                'textOnlyRoles' => is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [],
            ],
            'performance' => [
                'wooFragments' => get_option('nucleo_woo_fragments') === 'keep' ? 'keep' : 'off',
                'purgeSafelist' => $purge,
            ],
            'assets' => [
                'purgeMode' => get_option('nucleo_purge_mode') === 'strict' ? 'strict' : 'normal',
            ],
            'fonts' => [
                'subsets' => $subsets,
            ],
            'quality' => [
                'nonForcible' => $nonForcible,
                'thinWords' => $thinWords,
            ],
            'captcha' => Captcha::settings(),
            'onboarding' => [
                'dismissed' => (bool) get_user_meta(get_current_user_id(), 'nucleo_onboarding_dismissed', true),
            ],
        ];

        return $settings;
    }

    /**
     * Plugin che Nucleo sa riconoscere: active viene da class_exists, defined o function_exists.
     * @return list<array{id:string,name:string,active:bool,does:string}>
     */
    private static function integrations(): array
    {
        $plugins = Access::plugins();

        return [
            [
                'id' => 'woocommerce',
                'name' => 'WooCommerce',
                'active' => $plugins['woocommerce'],
                'does' => __('Adapts WooCommerce templates and styles to the Nucleo design system.', 'nucleo'),
            ],
            [
                'id' => 'polylang',
                'name' => 'Polylang',
                'active' => $plugins['polylang'],
                'does' => __('Uses the Polylang languages to create and link translations of documents.', 'nucleo'),
            ],
            [
                'id' => 'wpml',
                'name' => 'WPML',
                'active' => $plugins['wpml'],
                'does' => __('Uses the WPML languages to create and link translations of documents.', 'nucleo'),
            ],
            [
                'id' => 'yoast',
                'name' => 'Yoast SEO',
                'active' => $plugins['yoast'],
                'does' => __('Reads the SEO title and description set in Yoast when Nucleo describes a page.', 'nucleo'),
            ],
            [
                'id' => 'rankmath',
                'name' => 'Rank Math',
                'active' => $plugins['rankmath'],
                'does' => __('Reads the SEO title and description set in Rank Math when Nucleo describes a page.', 'nucleo'),
            ],
            [
                'id' => 'contact-form-7',
                'name' => 'Contact Form 7',
                'active' => defined('WPCF7_VERSION'),
                'does' => __('Applies the Nucleo styles to Contact Form 7 forms.', 'nucleo'),
            ],
            [
                'id' => 'complianz',
                'name' => 'Complianz',
                'active' => function_exists('cmplz_get_value'),
                'does' => __('Coordinates the loading of Nucleo assets with the Complianz consent banner.', 'nucleo'),
            ],
            [
                'id' => 'cookiebot',
                'name' => 'Cookiebot',
                'active' => class_exists('Cookiebot_WP'),
                'does' => __('Coordinates the loading of Nucleo assets with the Cookiebot consent banner.', 'nucleo'),
            ],
            [
                'id' => 'cookieyes',
                'name' => 'CookieYes',
                'active' => defined('CKY_VERSION'),
                'does' => __('Coordinates the loading of Nucleo assets with the CookieYes consent banner.', 'nucleo'),
            ],
            [
                'id' => 'iubenda',
                'name' => 'iubenda',
                'active' => defined('IUBENDA_VERSION'),
                'does' => __('Coordinates the loading of Nucleo assets with the iubenda consent banner.', 'nucleo'),
            ],
        ];
    }
}
