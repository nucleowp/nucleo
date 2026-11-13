<?php

declare(strict_types=1);

namespace Nucleo\OAuth;

defined('ABSPATH') || exit;

/**
 * Protezione SSRF per gli URL forniti dai client e verifica dei redirect URI.
 * Il loopback HTTP segue RFC 8252: la porta può variare.
 */
final class UrlGuard
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /** @var callable(string): list<string> */
    private $resolver;

    /** @param (callable(string): list<string>)|null $resolver */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? self::resolveDefault(...);
    }

    public function isSafePublicUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || $host === 'localhost') {
            return false;
        }

        $bare = trim($host, '[]');
        $ips = filter_var($bare, FILTER_VALIDATE_IP) !== false ? [$bare] : ($this->resolver)($bare);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * IP pubblico: esclude le reti private e riservate di PHP, più 100.64.0.0/10 (CGNAT) e 198.18.0.0/15
     * (benchmark), che PHP accetta e che alcune versioni di WordPress non bloccano.
     */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $long = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? ip2long($ip) : false;
        if ($long === false) {
            return true;
        }
        foreach ([['100.64.0.0', 10], ['198.18.0.0', 15]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($long & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $registered */
    public function isAllowedRedirect(string $uri, array $registered): bool
    {
        $target = wp_parse_url($uri);
        if (!is_array($target)) {
            return false;
        }
        if (strtolower($target['scheme'] ?? '') === 'http' && !self::isLoopbackHttp($target)) {
            return false;
        }
        if (in_array($uri, $registered, true)) {
            return true;
        }
        if (!self::isLoopbackHttp($target)) {
            return false;
        }
        foreach ($registered as $reg) {
            $candidate = wp_parse_url($reg);
            if (is_array($candidate) && self::isLoopbackHttp($candidate) && self::sameIgnoringPort($target, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed>|false $p */
    private static function isLoopbackHttp(array|false $p): bool
    {
        return is_array($p)
            && strtolower($p['scheme'] ?? '') === 'http'
            && in_array(strtolower($p['host'] ?? ''), self::LOOPBACK_HOSTS, true)
            && !isset($p['user'])
            && !isset($p['pass']);
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private static function sameIgnoringPort(array $a, array $b): bool
    {
        return strtolower($a['host'] ?? '') === strtolower($b['host'] ?? '')
            && ($a['path'] ?? '') === ($b['path'] ?? '')
            && ($a['query'] ?? '') === ($b['query'] ?? '')
            && ($a['fragment'] ?? '') === ($b['fragment'] ?? '');
    }

    /** @return list<string> */
    private static function resolveDefault(string $host): array
    {
        // Un fallimento DNS equivale a nessun IP: il chiamante rifiuta l'URL.
        $ips = @gethostbynamel($host);
        $ips = is_array($ips) ? $ips : [];
        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($aaaa) ? $aaaa : [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
