<?php

declare(strict_types=1);

namespace Nucleo\Forms;

/**
 * Captcha dei moduli nc/native-form: un provider per sito, chiavi nell'opzione nucleo_captcha.
 * Il segreto è in sola scrittura: settings() dice solo se è impostato.
 */
final class Captcha
{
    public const OPTION = 'nucleo_captcha';
    public const PROVIDERS = ['recaptcha', 'recaptcha-v3', 'hcaptcha', 'turnstile', 'friendly'];
    /** Azione di reCAPTCHA v3: lo script la passa a execute(), verify() la confronta. */
    public const ACTION = 'nucleo_form';
    public const DEFAULT_THRESHOLD = 0.5;

    private const KEYS = ['provider', 'siteKey', 'secret', 'threshold'];

    /** Handle degli script inclusi di Friendly Captcha: modulo per i browser moderni, versione compatibile per gli altri. */
    public const FRIENDLY_HANDLE = 'nucleo-friendlycaptcha';
    public const FRIENDLY_COMPAT_HANDLE = 'nucleo-friendlycaptcha-compat';

    /** @return array{provider:string,siteKey:string,secretSet:bool,threshold:float} provider '' = nessun captcha */
    public static function settings(): array
    {
        $stored = self::stored();

        return [
            'provider' => $stored['provider'],
            'siteKey' => $stored['siteKey'],
            'secretSet' => $stored['secret'] !== '',
            'threshold' => $stored['threshold'],
        ];
    }

    /** Messaggio d'errore del gruppo settings.captcha, null se valido. Non scrive nulla. */
    public static function check(mixed $input): ?string
    {
        if (!is_array($input)) {
            return __('"captcha" must be an object.', 'nucleo');
        }
        foreach (array_keys($input) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                /* translators: %s: name of an unknown captcha setting key. */
                return sprintf(__('Unknown captcha key: %s.', 'nucleo'), (string) $key);
            }
        }
        if (array_key_exists('provider', $input)) {
            $provider = $input['provider'];
            if (!is_string($provider) || ($provider !== '' && !in_array($provider, self::PROVIDERS, true))) {
                return __('"provider" must be empty or a supported captcha provider.', 'nucleo');
            }
        }
        if (array_key_exists('siteKey', $input) && !is_string($input['siteKey'])) {
            return __('"siteKey" must be a string.', 'nucleo');
        }
        if (array_key_exists('secret', $input) && !is_string($input['secret'])) {
            return __('"secret" must be a string.', 'nucleo');
        }
        if (array_key_exists('threshold', $input)) {
            $threshold = $input['threshold'];
            if (!(is_int($threshold) || is_float($threshold)) || !($threshold >= 0 && $threshold <= 1)) {
                return __('"threshold" must be a number between 0 and 1.', 'nucleo');
            }
        }

        $stored = self::stored();
        $provider = array_key_exists('provider', $input) ? $input['provider'] : $stored['provider'];
        $siteKey = trim(array_key_exists('siteKey', $input) ? (string) $input['siteKey'] : $stored['siteKey']);
        $secret = trim(array_key_exists('secret', $input) ? (string) $input['secret'] : $stored['secret']);
        if ($provider !== '' && ($siteKey === '' || $secret === '')) {
            return __('A captcha provider needs both a site key and a secret.', 'nucleo');
        }

        return null;
    }

    /** @param array<string,mixed> $input gruppo già controllato da check(); chiavi assenti restano com'erano */
    public static function save(array $input): void
    {
        $current = get_option(self::OPTION, []);
        $current = is_array($current) ? $current : [];
        foreach (['provider', 'siteKey', 'secret'] as $key) {
            if (array_key_exists($key, $input) && is_string($input[$key])) {
                $current[$key] = trim($input[$key]);
            }
        }
        $current['threshold'] = (float) ($input['threshold'] ?? $current['threshold'] ?? self::DEFAULT_THRESHOLD);
        update_option(self::OPTION, $current);
    }

    /** Provider, chiave del sito e segreto impostati. */
    public static function enabled(): bool
    {
        $stored = self::stored();

        return $stored['provider'] !== '' && $stored['siteKey'] !== '' && $stored['secret'] !== '';
    }

    /** Nome del campo del token inviato dal widget. */
    public static function field(string $provider): string
    {
        return match ($provider) {
            'recaptcha', 'recaptcha-v3' => 'g-recaptcha-response',
            'hcaptcha' => 'h-captcha-response',
            'turnstile' => 'cf-turnstile-response',
            'friendly' => 'frc-captcha-response',
            default => '',
        };
    }

    /** Markup del widget e script del provider (una volta per pagina); '' se non abilitato. */
    public static function widget(string $node): string
    {
        if (!self::enabled()) {
            return '';
        }
        $stored = self::stored();
        $provider = $stored['provider'];
        $siteKey = $stored['siteKey'];
        // Il modulo si rende durante la pagina: WordPress stampa gli script accodati qui nel footer, una sola volta.
        self::enqueue($provider, $siteKey);

        return sprintf(
            '<div class="nc-native-form__captcha" data-captcha="%s" data-sitekey="%s">%s</div>',
            esc_attr($provider),
            esc_attr($siteKey),
            self::markup($provider, $siteKey),
        );
    }

    /**
     * Verifica il token presso il provider.
     * @param array<string,mixed> $params corpo della richiesta
     */
    public static function verify(array $params, string $ip): bool
    {
        if (!self::enabled()) {
            return true;
        }
        $stored = self::stored();
        $provider = $stored['provider'];
        $token = $params[self::field($provider)] ?? null;
        if (!is_string($token) || $token === '') {
            return false;
        }

        $args = ['timeout' => 5.0];
        if ($provider === 'friendly') {
            $url = 'https://global.frcapi.com/api/v2/captcha/siteverify';
            $args['headers'] = ['X-API-Key' => $stored['secret'], 'Content-Type' => 'application/json'];
            $args['body'] = (string) wp_json_encode(['response' => $token, 'sitekey' => $stored['siteKey']]);
        } else {
            // phpcs:disable PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- verifica del token lato server, non uno script caricato nella pagina.
            $url = match ($provider) {
                'hcaptcha' => 'https://api.hcaptcha.com/siteverify',
                'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                default => 'https://www.google.com/recaptcha/api/siteverify',
            };
            // phpcs:enable PluginCheck.CodeAnalysis.Offloading.OffloadedContent
            $body = ['secret' => $stored['secret'], 'response' => $token];
            if (str_starts_with($provider, 'recaptcha')) {
                $body['remoteip'] = $ip;
            }
            if ($provider === 'hcaptcha') {
                $body['sitekey'] = $stored['siteKey'];
            }
            $args['body'] = $body;
        }

        $response = wp_remote_post($url, $args);
        // Il provider che non risponde non deve bloccare i moduli: fail-open.
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 500) {
            return true;
        }

        $result = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($result) || ($result['success'] ?? null) !== true) {
            return false;
        }
        if ($provider !== 'recaptcha-v3') {
            return true;
        }
        $score = $result['score'] ?? null;

        return is_numeric($score)
            && (float) $score >= $stored['threshold']
            && ($result['action'] ?? null) === self::ACTION;
    }

    /**
     * Opzione normalizzata: provider valido o '', valori stringa, soglia tra 0 e 1.
     * @return array{provider:string,siteKey:string,secret:string,threshold:float}
     */
    private static function stored(): array
    {
        $raw = get_option(self::OPTION, []);
        $raw = is_array($raw) ? $raw : [];
        $provider = $raw['provider'] ?? '';
        $threshold = $raw['threshold'] ?? null;

        return [
            'provider' => is_string($provider) && in_array($provider, self::PROVIDERS, true) ? $provider : '',
            'siteKey' => is_string($raw['siteKey'] ?? null) ? $raw['siteKey'] : '',
            'secret' => is_string($raw['secret'] ?? null) ? $raw['secret'] : '',
            'threshold' => (is_int($threshold) || is_float($threshold)) && $threshold >= 0 && $threshold <= 1
                ? (float) $threshold
                : self::DEFAULT_THRESHOLD,
        ];
    }

    /** Elemento che il provider trasforma nel widget. */
    private static function markup(string $provider, string $siteKey): string
    {
        $key = esc_attr($siteKey);

        return match ($provider) {
            'recaptcha' => '<div class="g-recaptcha" data-sitekey="' . $key . '"></div>',
            'recaptcha-v3' => '<input type="hidden" name="g-recaptcha-response">',
            'hcaptcha' => '<div class="h-captcha" data-sitekey="' . $key . '"></div>',
            'turnstile' => '<div class="cf-turnstile" data-sitekey="' . $key . '"></div>',
            'friendly' => '<div class="frc-captcha" data-sitekey="' . $key . '"></div>',
            default => '',
        };
    }

    /**
     * Handle dello script del provider; WordPress lo stampa una volta anche con più moduli nella pagina.
     * @return non-empty-string
     */
    public static function handle(string $provider): string
    {
        return $provider === 'friendly' ? self::FRIENDLY_HANDLE : 'nucleo-captcha-' . $provider;
    }

    /** Accoda lo script del provider nel footer. */
    private static function enqueue(string $provider, string $siteKey): void
    {
        if ($provider === 'friendly') {
            wp_enqueue_script(self::FRIENDLY_HANDLE, plugins_url('assets/lib/friendlycaptcha/site.min.js', NUCLEO_FILE), [], NUCLEO_VERSION, ['in_footer' => true]);
            wp_enqueue_script(self::FRIENDLY_COMPAT_HANDLE, plugins_url('assets/lib/friendlycaptcha/site.compat.min.js', NUCLEO_FILE), [], NUCLEO_VERSION, ['in_footer' => true]);
            if (!has_filter('wp_script_attributes', [self::class, 'scriptAttributes'])) {
                add_filter('wp_script_attributes', [self::class, 'scriptAttributes']);
            }

            return;
        }
        // phpcs:disable PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- servizi captcha scelti dall'utente, elencati in readme.txt, External services.
        $src = match ($provider) {
            'recaptcha' => 'https://www.google.com/recaptcha/api.js',
            'recaptcha-v3' => 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode($siteKey),
            'hcaptcha' => 'https://js.hcaptcha.com/1/api.js',
            'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            default => '',
        };
        // phpcs:enable PluginCheck.CodeAnalysis.Offloading.OffloadedContent
        if ($src !== '') {
            // Versione null: l'URL del provider resta quello documentato, senza ?ver.
            wp_enqueue_script(self::handle($provider), $src, [], null, ['in_footer' => true, 'strategy' => 'async']); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- script del provider, versionato da lui.
        }
    }

    /**
     * Attributi degli script di Friendly Captcha: type="module" per la versione moderna, nomodule per quella compatibile.
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    public static function scriptAttributes(array $attributes): array
    {
        $id = $attributes['id'] ?? '';
        if ($id === self::FRIENDLY_HANDLE . '-js') {
            $attributes['type'] = 'module';
        } elseif ($id === self::FRIENDLY_COMPAT_HANDLE . '-js') {
            $attributes['nomodule'] = true;
        }

        return $attributes;
    }
}
