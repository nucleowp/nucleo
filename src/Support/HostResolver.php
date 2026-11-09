<?php

declare(strict_types=1);

namespace Nucleo\Support;

/**
 * Unico punto di risoluzione degli host del plugin. Il filtro nucleo/resolve_host, se restituisce un elenco,
 * sostituisce il DNS reale: i test usano così una risoluzione finta e non dipendono dalla rete.
 */
final class HostResolver
{
    /** @return list<string> */
    public static function resolve(string $host): array
    {
        $filtered = apply_filters('nucleo/resolve_host', null, $host);
        if (is_array($filtered)) {
            return array_values(array_filter($filtered, 'is_string'));
        }

        // Un fallimento DNS equivale a nessun IP: il chiamante rifiuta l'host.
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
