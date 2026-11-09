<?php

declare(strict_types=1);

namespace Nucleo\Support;

/**
 * IP del visitatore per i limiti di frequenza. Predefinito: REMOTE_ADDR.
 * Dietro un reverse proxy il filtro nucleo/trusted_proxies elenca gli IP dei proxy: solo se la richiesta arriva
 * da uno di loro vale l'ultimo indirizzo di X-Forwarded-For che non è a sua volta un proxy.
 */
final class ClientIp
{
    public static function get(): string
    {
        $remote = self::valid(sanitize_text_field(wp_unslash((string) ($_SERVER['REMOTE_ADDR'] ?? ''))));
        $trusted = apply_filters('nucleo/trusted_proxies', []);
        if ($remote === '' || !is_array($trusted) || !in_array($remote, $trusted, true)) {
            return $remote;
        }

        $forwarded = sanitize_text_field(wp_unslash((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
        // Da destra a sinistra: gli indirizzi più a destra li hanno aggiunti i proxy fidati.
        foreach (array_reverse(array_map('trim', explode(',', $forwarded))) as $ip) {
            $ip = self::valid($ip);
            if ($ip !== '' && !in_array($ip, $trusted, true)) {
                return $ip;
            }
        }

        return $remote;
    }

    private static function valid(string $ip): string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }
}
