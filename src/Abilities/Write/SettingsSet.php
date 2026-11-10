<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Abilities\Read\SettingsGet;
use Nucleo\Forms\Captcha;

/** nucleo/settings-set: salva le impostazioni di Nucleo in modo parziale, validando tutto prima di scrivere. */
final class SettingsSet implements AbilityDefinition
{
    private const GROUPS = [
        'editor', 'performance', 'assets', 'fonts', 'quality',
        'captcha', 'onboarding',
    ];

    public function name(): string
    {
        return 'nucleo/settings-set';
    }

    public function args(): array
    {
        return [
            'label' => __('Save settings', 'nucleo'),
            'description' => 'Saves Nucleo settings. Partial: groups and keys left out are kept. Every value is checked before anything is written. '
                . 'The captcha secret is write-only and is never returned. WordPress site options (title, tagline, homepage, date formats, icons) go through nucleo/site-options-set, design tokens through nucleo/tokens-set.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['settings'],
                'properties' => [
                    'settings' => [
                        'type' => 'object',
                        'properties' => [
                            'editor' => [
                                'type' => 'object',
                                'properties' => [
                                    'textOnlyRoles' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'WordPress role slugs.'],
                                ],
                                'additionalProperties' => false,
                            ],
                            'performance' => [
                                'type' => 'object',
                                'properties' => [
                                    'wooFragments' => ['type' => 'string', 'description' => '"keep" or "off". Validated by the ability.'],
                                    'purgeSafelist' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'CSS selectors to keep when purging.'],
                                ],
                                'additionalProperties' => false,
                            ],
                            'assets' => [
                                'type' => 'object',
                                'properties' => [
                                    'purgeMode' => ['type' => 'string', 'enum' => ['normal', 'strict'], 'description' => 'CSS purge mode: "normal" or "strict".'],
                                ],
                                'additionalProperties' => false,
                            ],
                            'fonts' => [
                                'type' => 'object',
                                'properties' => [
                                    'subsets' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Font subset slugs, at most 10. Empty list means latin and latin-ext.'],
                                ],
                                'additionalProperties' => false,
                            ],
                            'quality' => [
                                'type' => 'object',
                                'properties' => [
                                    'nonForcible' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Quality rule ids that cannot be overridden to publish, at most 50.'],
                                    'thinWords' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 5000], 'description' => 'Word count per post type, from 0 to 5000.'],
                                ],
                                'additionalProperties' => false,
                            ],
                            'captcha' => [
                                'type' => 'object',
                                'description' => 'Captcha of the native forms (nc/native-form), one provider for the whole site. Every native form shows it unless its settings.captcha is false.',
                                'properties' => [
                                    'provider' => ['type' => 'string', 'enum' => ['', ...Captcha::PROVIDERS], 'description' => 'recaptcha (v2 checkbox), recaptcha-v3 (invisible, with score), hcaptcha, turnstile (Cloudflare), friendly (Friendly Captcha v2), or empty to turn it off.'],
                                    'siteKey' => ['type' => 'string', 'description' => 'Public site key of the provider.'],
                                    'secret' => ['type' => 'string', 'description' => 'Secret key (Friendly Captcha: API key). Omit to keep the stored one.'],
                                    'threshold' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'description' => 'reCAPTCHA v3 only: minimum score, default 0.5.'],
                                ],
                                'additionalProperties' => false,
                            ],
                            'onboarding' => [
                                'type' => 'object',
                                'properties' => [
                                    'dismissed' => ['type' => 'boolean', 'description' => 'Applies to the current user only.'],
                                ],
                                'additionalProperties' => false,
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'settings' => [
                        'type' => 'object',
                        'description' => 'Same shape as settings-get, without integrations.',
                    ],
                    'error' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $settings = $input['settings'] ?? null;
        if (!is_array($settings)) {
            return self::invalid(__('"settings" must be an object.', 'nucleo'));
        }

        $error = self::validate($settings);
        if ($error !== null) {
            return self::invalid($error);
        }

        self::save($settings);

        return ['ok' => true, 'settings' => SettingsGet::settings()];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Controlla tutti i gruppi prima di scrivere: al primo valore non valido restituisce il messaggio.
     * @param array<string,mixed> $settings
     */
    private static function validate(array $settings): ?string
    {
        $unknown = array_diff(array_keys($settings), self::GROUPS);
        if ($unknown !== []) {
            /* translators: %s: name of an unknown settings group. */
            return sprintf(__('Unknown settings group: %s.', 'nucleo'), (string) reset($unknown));
        }

        if (array_key_exists('editor', $settings)) {
            if (!is_array($settings['editor'])) {
                return __('"editor" must be an object.', 'nucleo');
            }
            if (array_key_exists('textOnlyRoles', $settings['editor'])) {
                $roles = $settings['editor']['textOnlyRoles'];
                $known = array_keys(wp_roles()->roles);
                if (!is_array($roles) || !array_is_list($roles)) {
                    return __('"textOnlyRoles" must be a list of role names.', 'nucleo');
                }
                foreach ($roles as $role) {
                    if (!is_string($role) || !in_array($role, $known, true)) {
                        /* translators: %s: unknown WordPress role name. */
                        return sprintf(__('Unknown role: %s.', 'nucleo'), is_string($role) ? $role : gettype($role));
                    }
                }
            }
        }

        if (array_key_exists('performance', $settings)) {
            if (!is_array($settings['performance'])) {
                return __('"performance" must be an object.', 'nucleo');
            }
            if (
                array_key_exists('wooFragments', $settings['performance'])
                && !in_array($settings['performance']['wooFragments'], ['keep', 'off'], true)
            ) {
                return __('"wooFragments" must be "keep" or "off".', 'nucleo');
            }
            if (array_key_exists('purgeSafelist', $settings['performance'])) {
                $list = $settings['performance']['purgeSafelist'];
                if (!is_array($list) || !array_is_list($list)) {
                    return __('"purgeSafelist" must be a list of selectors.', 'nucleo');
                }
                foreach ($list as $selector) {
                    if (!is_string($selector)) {
                        return __('"purgeSafelist" must contain only strings.', 'nucleo');
                    }
                }
            }
        }

        if (array_key_exists('assets', $settings)) {
            if (!is_array($settings['assets'])) {
                return __('"assets" must be an object.', 'nucleo');
            }
            if (array_key_exists('purgeMode', $settings['assets']) && !in_array($settings['assets']['purgeMode'], ['normal', 'strict'], true)) {
                return __('"purgeMode" must be "normal" or "strict".', 'nucleo');
            }
        }

        if (array_key_exists('fonts', $settings)) {
            if (!is_array($settings['fonts'])) {
                return __('"fonts" must be an object.', 'nucleo');
            }
            if (array_key_exists('subsets', $settings['fonts'])) {
                $error = self::checkList($settings['fonts']['subsets'], 'subsets', '/^[a-z0-9-]+$/D', 10);
                if ($error !== null) {
                    return $error;
                }
            }
        }

        if (array_key_exists('quality', $settings)) {
            if (!is_array($settings['quality'])) {
                return __('"quality" must be an object.', 'nucleo');
            }
            if (array_key_exists('nonForcible', $settings['quality'])) {
                $error = self::checkList($settings['quality']['nonForcible'], 'nonForcible', '/^[a-z0-9_.-]+$/D', 50);
                if ($error !== null) {
                    return $error;
                }
            }
            if (array_key_exists('thinWords', $settings['quality'])) {
                $map = $settings['quality']['thinWords'];
                if (!is_array($map) || ($map !== [] && array_is_list($map))) {
                    return __('"thinWords" must be an object of post types and word counts.', 'nucleo');
                }
                foreach ($map as $type => $words) {
                    if (!is_string($type) || preg_match('/^[a-z0-9_-]{1,20}$/D', $type) !== 1) {
                        /* translators: %s: invalid post type name. */
                        return sprintf(__('Invalid post type in "thinWords": %s.', 'nucleo'), (string) $type);
                    }
                    if (!is_int($words) || $words < 0 || $words > 5000) {
                        return __('"thinWords" values must be whole numbers from 0 to 5000.', 'nucleo');
                    }
                }
            }
        }


        if (array_key_exists('captcha', $settings)) {
            $error = Captcha::check($settings['captcha']);
            if ($error !== null) {
                return $error;
            }
        }

        if (array_key_exists('onboarding', $settings)) {
            if (!is_array($settings['onboarding'])) {
                return __('"onboarding" must be an object.', 'nucleo');
            }
            if (array_key_exists('dismissed', $settings['onboarding']) && !is_bool($settings['onboarding']['dismissed'])) {
                return __('"dismissed" must be a boolean.', 'nucleo');
            }
        }

        return null;
    }

    /**
     * Lista di slug: forma, numero massimo e pattern di ogni voce. Restituisce il messaggio o null.
     * @param mixed $list
     */
    private static function checkList(mixed $list, string $key, string $pattern, int $max): ?string
    {
        if (!is_array($list) || !array_is_list($list)) {
            /* translators: %s: name of a settings key. */
            return sprintf(__('"%s" must be a list.', 'nucleo'), $key);
        }
        if (count($list) > $max) {
            /* translators: 1: name of a settings key, 2: maximum number of items. */
            return sprintf(__('"%1$s" can have at most %2$d items.', 'nucleo'), $key, $max);
        }
        foreach ($list as $item) {
            if (!is_string($item) || preg_match($pattern, $item) !== 1) {
                /* translators: %s: name of a settings key. */
                return sprintf(__('"%s" contains an invalid value.', 'nucleo'), $key);
            }
        }

        return null;
    }

    /**
     * Scrive solo i valori presenti: una chiave assente resta com'è. Chiamata solo dopo validate().
     * @param array<string,mixed> $settings
     */
    private static function save(array $settings): void
    {
        $stored = get_option('nucleo_settings', []);
        $stored = is_array($stored) ? $stored : [];
        $editor = $settings['editor'] ?? [];
        if (array_key_exists('textOnlyRoles', $editor)) {
            $stored['editor'] = is_array($stored['editor'] ?? null) ? $stored['editor'] : [];
            $stored['editor']['textOnlyRoles'] = array_values(array_unique($editor['textOnlyRoles']));
        }
        update_option('nucleo_settings', $stored);

        $performance = $settings['performance'] ?? [];
        if (array_key_exists('wooFragments', $performance)) {
            if ($performance['wooFragments'] === 'keep') {
                update_option('nucleo_woo_fragments', 'keep');
            } else {
                delete_option('nucleo_woo_fragments');
            }
        }
        if (array_key_exists('purgeSafelist', $performance)) {
            $selectors = array_map('trim', $performance['purgeSafelist']);
            update_option('nucleo_purge_safelist', array_values(array_unique(array_filter($selectors, static fn (string $s): bool => $s !== ''))));
        }

        $assets = $settings['assets'] ?? [];
        if (array_key_exists('purgeMode', $assets)) {
            update_option('nucleo_purge_mode', $assets['purgeMode']);
        }

        $fonts = $settings['fonts'] ?? [];
        if (array_key_exists('subsets', $fonts)) {
            update_option('nucleo_font_subsets', array_values(array_unique($fonts['subsets'])));
        }

        $quality = $settings['quality'] ?? [];
        if (array_key_exists('nonForcible', $quality) || array_key_exists('thinWords', $quality)) {
            $stored = get_option('nucleo_quality', []);
            $stored = is_array($stored) ? $stored : [];
            if (array_key_exists('nonForcible', $quality)) {
                $stored['nonForcible'] = array_values(array_unique($quality['nonForcible']));
            }
            if (array_key_exists('thinWords', $quality)) {
                $stored['thinWords'] = $quality['thinWords'];
            }
            update_option('nucleo_quality', $stored);
        }


        if (is_array($settings['captcha'] ?? null)) {
            Captcha::save($settings['captcha']);
        }

        $onboarding = $settings['onboarding'] ?? [];
        if (array_key_exists('dismissed', $onboarding)) {
            $uid = get_current_user_id();
            if ($onboarding['dismissed']) {
                update_user_meta($uid, 'nucleo_onboarding_dismissed', true);
            } else {
                delete_user_meta($uid, 'nucleo_onboarding_dismissed');
            }
        }
    }

    /** @return array{ok:false,error:array{code:string,message:string}} */
    private static function invalid(string $message): array
    {
        return ['ok' => false, 'error' => ['code' => 'invalid_input', 'message' => $message]];
    }
}
