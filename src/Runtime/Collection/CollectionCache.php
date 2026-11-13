<?php

declare(strict_types=1);

namespace Nucleo\Runtime\Collection;

defined('ABSPATH') || exit;

use Nucleo\Extend\Components;

/** Cache delle pagine di risultati in transient, invalidata da ContentVersion. */
final class CollectionCache
{
    /** 12 ore. */
    private const TTL = 43200;
    /** Sorgente component senza ttl valido nello schema. */
    private const COMPONENT_TTL = 300;

    /** @param array<string,mixed> $template */
    public static function page(int $doc, CollectionConfig $config, CollectionState $state, array $template): CollectionPage
    {
        $ttl = self::ttl($config);
        // Ttl 0 o ricerca testuale attiva: nessuna lettura né scrittura della cache.
        if ($ttl === 0 || self::hasText($config, $state)) {
            return CollectionQuery::page($config, $state, $template);
        }

        $key = 'nucleo_coll_' . md5((string) wp_json_encode([
            $doc,
            $config->toArray(),
            md5((string) wp_json_encode($template)),
            $state->key(),
            ContentVersion::get(),
            // L'HTML delle schede contiene date ed etichette nella lingua della richiesta.
            determine_locale(),
        ]));

        $cached = get_transient($key);
        if (is_array($cached)) {
            $page = CollectionPage::fromArray($cached);
            if ($page !== null) {
                return $page;
            }
        }

        $page = CollectionQuery::page($config, $state, $template);
        // Solo stati con valori dichiarati: un visitatore non deve poter creare una voce per ogni valore inventato.
        if (self::isBounded($state, $page)) {
            set_transient($key, $page->toArray(), $ttl);
        }

        return $page;
    }

    /** Ogni valore di opzione è tra le opzioni della pagina e nessun estremo di range è impostato. */
    private static function isBounded(CollectionState $state, CollectionPage $page): bool
    {
        foreach ($state->values as $name => $value) {
            if (!array_is_list($value)) {
                return false;
            }
            $known = array_column($page->options[(string) $name] ?? [], 'value');
            if (array_diff($value, $known) !== []) {
                return false;
            }
        }

        return true;
    }

    /** Durata in secondi: schema()['ttl'] della sorgente component se intero >= 0, altrimenti 300; posts 12 ore. */
    private static function ttl(CollectionConfig $config): int
    {
        if ($config->source !== 'component') {
            return self::TTL;
        }
        $source = Components::instance()->dataSource($config->component);
        $ttl = $source?->schema()['ttl'] ?? null;

        return is_int($ttl) && $ttl >= 0 ? $ttl : self::COMPONENT_TTL;
    }

    /** Vero se è attivo un filtro search, o un param con ui text, con un valore. */
    private static function hasText(CollectionConfig $config, CollectionState $state): bool
    {
        foreach (array_keys($state->values) as $key) {
            $filter = $config->filter((string) $key);
            if ($filter !== null && ($filter['type'] === 'search' || $filter['ui'] === 'text')) {
                return true;
            }
        }

        return false;
    }
}
