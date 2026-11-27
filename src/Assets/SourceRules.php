<?php

declare(strict_types=1);

namespace Nucleo\Assets;

defined('ABSPATH') || exit;

/**
 * Regole sugli script stampati fuori dalla coda di WordPress: si riconoscono da una parte dell'URL (src)
 * o da una parte del codice inline (code). Classe pura, senza WordPress.
 *
 * Voce compilata (AssetRules::compileSources): array{src?: string, code?: string, strategy: string, except: list<string>, force?: true, replay?: string}
 */
final class SourceRules
{
    /** Tipi che non si ritardano senza force: il banner del consenso deve comparire subito, pagamenti e captcha si rompono. */
    public const NEVER_KINDS = ['consent', 'payment', 'captcha'];
    /** Tipi di misura e pubblicità: ritardarli toglie le visite di chi non interagisce. Solo per scelta dell'utente. */
    public const TRACKING_KINDS = ['analytics', 'tag-manager', 'ads'];

    // phpcs:disable PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- elenco per riconoscere gli script già presenti nella pagina: il plugin non carica nulla da questi domini.
    /** Parte di URL o di codice => tipo. Vince la prima voce contenuta nel testo, senza distinguere le maiuscole. */
    private const KINDS = [
        'googletagmanager.com/gtag/js' => 'analytics',
        'google-analytics.com' => 'analytics',
        'gtag(' => 'analytics',
        'plausible.io' => 'analytics',
        'matomo' => 'analytics',
        '_paq.push' => 'analytics',
        'clarity.ms' => 'analytics',
        'hotjar.com' => 'analytics',
        'cloudflareinsights.com' => 'analytics',
        'usefathom.com' => 'analytics',
        'stats.wp.com' => 'analytics',
        'googletagmanager.com' => 'tag-manager',
        'connect.facebook.net' => 'ads',
        'fbq(' => 'ads',
        'googleadservices.com' => 'ads',
        'googlesyndication.com' => 'ads',
        'doubleclick.net' => 'ads',
        'snap.licdn.com' => 'ads',
        'analytics.tiktok.com' => 'ads',
        'ttq.load' => 'ads',
        'ads-twitter.com' => 'ads',
        'bat.bing.com' => 'ads',
        'cookiebot.com' => 'consent',
        'iubenda.com' => 'consent',
        'cookieyes.com' => 'consent',
        'onetrust.com' => 'consent',
        'cookielaw.org' => 'consent',
        'complianz' => 'consent',
        'usercentrics' => 'consent',
        'cookie-script.com' => 'consent',
        'termly.io' => 'consent',
        'osano.com' => 'consent',
        'js.stripe.com' => 'payment',
        'paypal.com/sdk' => 'payment',
        'paypalobjects.com' => 'payment',
        'braintreegateway.com' => 'payment',
        'checkout.razorpay.com' => 'payment',
        'squarecdn.com' => 'payment',
        'klarna' => 'payment',
        'pay.google.com' => 'payment',
        'mollie.com' => 'payment',
        'adyen.com' => 'payment',
        'google.com/recaptcha' => 'captcha',
        'gstatic.com/recaptcha' => 'captcha',
        'grecaptcha' => 'captcha',
        'hcaptcha.com' => 'captcha',
        'challenges.cloudflare.com' => 'captcha',
        'tawk.to' => 'chat',
        'crisp.chat' => 'chat',
        'intercom.io' => 'chat',
        'tidio.co' => 'chat',
        'livechatinc.com' => 'chat',
        'zdassets.com' => 'chat',
        'smartsupp.com' => 'chat',
        'drift.com' => 'chat',
        'youtube.com/iframe_api' => 'video',
        'player.vimeo.com' => 'video',
        'maps.googleapis.com' => 'maps',
        'platform.twitter.com' => 'social',
        'instagram.com/embed.js' => 'social',
        'addtoany.com' => 'social',
        'sharethis.com' => 'social',
        'addthis.com' => 'social',
    ];
    // phpcs:enable PluginCheck.CodeAnalysis.Offloading.OffloadedContent

    /** Suffissi degli id dei tag stampati dalla coda di WordPress (script, dati, inline, traduzioni, moduli), dal più lungo. */
    private const QUEUE_SUFFIXES = ['-js-translations', '-js-before', '-js-module', '-js-after', '-js-extra', '-js'];

    /** Tipo noto di uno script, dal suo URL o dal suo codice; null se non è tra quelli noti. */
    public static function kind(string $text): ?string
    {
        $text = strtolower($text);
        foreach (self::KINDS as $part => $kind) {
            if (str_contains($text, $part)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Consiglio per uno script fuori coda: 'never' (NEVER_KINDS), 'explicit' (TRACKING_KINDS: solo se l'utente lo chiede),
     * 'delay' (altri tipi noti), 'review' (tipo sconosciuto).
     */
    public static function advice(?string $kind): string
    {
        if (!in_array($kind, self::KINDS, true)) {
            return 'review';
        }
        if (in_array($kind, self::NEVER_KINDS, true)) {
            return 'never';
        }
        if (in_array($kind, self::TRACKING_KINDS, true)) {
            return 'explicit';
        }

        return 'delay';
    }

    /**
     * Handle della coda per l'id di un tag: 'jquery-core-js-after' dà 'jquery-core', 'foo-js-module' dà 'foo'.
     * null se l'id è null, vuoto o senza un suffisso di QUEUE_SUFFIXES, o se prima del suffisso non resta nulla.
     */
    public static function queueHandle(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        foreach (self::QUEUE_SUFFIXES as $suffix) {
            if (str_ends_with($id, $suffix)) {
                $handle = substr($id, 0, -strlen($suffix));

                return $handle === '' ? null : $handle;
            }
        }

        return null;
    }

    /**
     * Spazi bianchi ridotti a uno solo e bordi tagliati: il codice confrontato non dipende da a capo e indentazione.
     */
    public static function squash(string $code): string
    {
        return trim(preg_replace('/\s+/', ' ', $code) ?? $code);
    }

    /**
     * La voce riconosce il tag. Voce con src: $src non null e contiene la parte, senza distinguere le maiuscole.
     * Voce con code: $src null, $code non vuoto e contiene la parte squashata, maiuscole comprese.
     * $code per le voci code è già squashato da pick().
     *
     * @param array<string, mixed> $rule voce compilata
     */
    public static function matches(array $rule, ?string $src, string $code): bool
    {
        if (is_string($rule['src'] ?? null)) {
            return $src !== null && str_contains(strtolower($src), strtolower($rule['src']));
        }
        if (is_string($rule['code'] ?? null)) {
            return $src === null && $code !== '' && str_contains($code, self::squash($rule['code']));
        }

        return false;
    }

    /**
     * Voci che valgono per la pagina, nell'ordine: chiave d'oggetto, template, '*'. Una voce con except che contiene
     * il template o la chiave d'oggetto non vale. Scope vuoti ('') si saltano; uno scope ripetuto conta una volta.
     *
     * @param array<string, mixed> $sources chiave 'sources' dell'artefatto: scope => list<voce>
     * @return list<array<string, mixed>>
     */
    public static function applicable(array $sources, string $template, string $objectKey): array
    {
        $scopes = array_values(array_unique(array_filter(
            [$objectKey, $template, '*'],
            static fn (string $scope): bool => $scope !== ''
        )));

        $rules = [];
        foreach ($scopes as $scope) {
            foreach ($sources[$scope] ?? [] as $rule) {
                $except = $rule['except'] ?? [];
                if (in_array($template, $except, true) || in_array($objectKey, $except, true)) {
                    continue;
                }
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * La prima voce che riconosce il tag decide. Se il tipo del tag (kind di $src, o di $code senza src) è in NEVER_KINDS
     * e la voce non ha force true, il tag resta com'è: null. Nessuna voce che lo riconosce: null.
     *
     * @param list<array<string, mixed>> $rules voci di applicable()
     * @return array<string, mixed>|null
     */
    public static function pick(array $rules, ?string $src, string $code): ?array
    {
        $flat = null;
        foreach ($rules as $rule) {
            if (is_string($rule['code'] ?? null)) {
                $flat ??= self::squash($code);
            }
            if (!self::matches($rule, $src, $flat ?? $code)) {
                continue;
            }
            $kind = self::kind($src ?? $code);
            if (in_array($kind, self::NEVER_KINDS, true) && ($rule['force'] ?? null) !== true) {
                return null;
            }

            return $rule;
        }

        return null;
    }
}
