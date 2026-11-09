<?php

declare(strict_types=1);

namespace Nucleo\Runtime;

/**
 * Voce corrente dei menu nc/menu, calcolata a ogni richiesta: l'HTML compilato è lo stesso per tutte le pagine.
 * I link delle voci portano data-nc-mi (id della voce), data-nc-mt ("post:<id>" o "term:<id>") e data-nc-ma
 * (id delle voci antenate, separati da spazio): vedi MenuRenderer.
 */
final class MenuCurrent
{
    /**
     * Aggiunge aria-current="page" e la classe is-current al link della voce corrente, is-ancestor ai link delle sue antenate.
     *
     * @param list<string> $keys chiavi dell'oggetto richiesto, es. ["post:5"] o ["term:7"]
     * @param string $url URL della richiesta, con la query ma senza ancora
     */
    public static function mark(string $html, array $keys, string $url): string
    {
        $target = self::parts($url);
        // Il confronto per URL vale solo se nessuna voce combacia per chiave.
        $byUrl = $url !== '' && !self::hasKeyMatch($html, $keys);
        $found = false;
        $ancestors = [];

        preg_match_all('/<a\b[^>]*>/', $html, $matches);
        foreach ($matches[0] as $tag) {
            if (self::attr($tag, 'data-nc-mi') === null || !self::isCurrent($tag, $keys, $target, $byUrl)) {
                continue;
            }
            $found = true;
            foreach (array_filter(explode(' ', self::attr($tag, 'data-nc-ma') ?? '')) as $ancestor) {
                $ancestors[$ancestor] = true;
            }
        }

        if (!$found) {
            return $html;
        }

        return preg_replace_callback(
            '/<a\b[^>]*>/',
            static function (array $match) use ($keys, $target, $byUrl, $ancestors): string {
                $tag = $match[0];
                $id = self::attr($tag, 'data-nc-mi');
                if ($id === null) {
                    return $tag;
                }
                if (self::isCurrent($tag, $keys, $target, $byUrl)) {
                    return self::classed($tag, 'is-current', true);
                }

                return isset($ancestors[$id]) ? self::classed($tag, 'is-ancestor', false) : $tag;
            },
            $html,
        ) ?? $html;
    }

    /** mark() con chiavi e URL della richiesta corrente; l'HTML senza data-nc-mi torna invariato senza altri calcoli. */
    public static function apply(string $html): string
    {
        if (!str_contains($html, 'data-nc-mi')) {
            return $html;
        }

        return self::mark($html, self::keys(), self::url());
    }

    /** @return list<string> */
    private static function keys(): array
    {
        $object = function_exists('get_queried_object') ? get_queried_object() : null;
        if ($object instanceof \WP_Post) {
            return ['post:' . $object->ID];
        }
        if ($object instanceof \WP_Term) {
            return ['term:' . $object->term_id];
        }

        return [];
    }

    private static function url(): string
    {
        if (!function_exists('home_url')) {
            return '';
        }

        global $wp;
        $url = home_url(isset($wp->request) ? (string) $wp->request : '');

        // La query entra solo nel confronto con gli href, passata da esc_url_raw.
        $query = isset($_SERVER['QUERY_STRING']) ? sanitize_text_field(wp_unslash((string) $_SERVER['QUERY_STRING'])) : '';
        if ($query !== '') {
            $url .= '?' . $query;
        }

        return function_exists('esc_url_raw') ? esc_url_raw($url) : $url;
    }

    /** @param list<string> $keys */
    private static function hasKeyMatch(string $html, array $keys): bool
    {
        preg_match_all('/<a\b[^>]*>/', $html, $matches);
        foreach ($matches[0] as $tag) {
            $type = self::attr($tag, 'data-nc-mt');
            if ($type !== null && in_array($type, $keys, true) && !str_starts_with(self::attr($tag, 'href') ?? '', '#')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $keys
     * @param array{base:string,path:string,query:array<int|string,mixed>} $target
     */
    private static function isCurrent(string $tag, array $keys, array $target, bool $byUrl): bool
    {
        $href = self::attr($tag, 'href') ?? '';
        if (str_starts_with($href, '#')) {
            return false;
        }

        $type = self::attr($tag, 'data-nc-mt');
        if ($type !== null && in_array($type, $keys, true)) {
            return true;
        }

        if (!$byUrl || $href === '') {
            return false;
        }

        // Href relativo alla radice: risolto con l'origine dell'URL della richiesta.
        if (str_starts_with($href, '/') && !str_starts_with($href, '//')) {
            $href = $target['base'] . $href;
        }

        $link = self::parts($href);
        if ($link['base'] !== $target['base'] || $link['path'] !== $target['path']) {
            return false;
        }

        if ($link['query'] !== []) {
            // Ogni coppia dell'href deve comparire nella query della richiesta.
            foreach ($link['query'] as $key => $value) {
                if (!array_key_exists($key, $target['query']) || $target['query'][$key] !== $value) {
                    return false;
                }
            }

            return true;
        }

        // Href senza query: la query della richiesta si ignora, tranne alla radice
        // (con i permalink semplici "/?page_id=10" non è la home). Qui conta solo il parametro di tracciamento utm_*.
        if ($link['path'] !== '') {
            return true;
        }
        foreach (array_keys($target['query']) as $key) {
            if (!str_starts_with((string) $key, 'utm_')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scompone un URL: origine (schema e host, minuscoli), percorso senza slash finale e query come coppie.
     * L'ancora è ignorata.
     *
     * @return array{base:string,path:string,query:array<int|string,mixed>}
     */
    private static function parts(string $url): array
    {
        $url = (string) preg_replace('/#.*/s', '', $url);
        $queryString = '';
        $pos = strpos($url, '?');
        if ($pos !== false) {
            $queryString = substr($url, $pos + 1);
            $url = substr($url, 0, $pos);
        }

        $base = '';
        if (preg_match('#^[a-z][a-z0-9+.-]*://[^/]*#i', $url, $m) === 1) {
            $base = strtolower($m[0]);
            $url = substr($url, strlen($m[0]));
        }

        $query = [];
        parse_str($queryString, $query);

        return ['base' => $base, 'path' => rtrim($url, '/'), 'query' => $query];
    }

    /** Valore decodificato dell'attributo, o null se assente. */
    private static function attr(string $tag, string $name): ?string
    {
        if (preg_match('/\s' . preg_quote($name, '/') . '="([^"]*)"/', $tag, $m) !== 1) {
            return null;
        }

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Aggiunge la classe all'attributo class esistente e, se richiesto, aria-current="page". */
    private static function classed(string $tag, string $class, bool $current): string
    {
        $tag = (string) preg_replace_callback(
            '/(\sclass=")([^"]*)"/',
            static fn (array $m): string => $m[1] . trim($m[2] . ' ' . $class) . '"',
            $tag,
            1,
        );

        return $current ? substr($tag, 0, -1) . ' aria-current="page">' : $tag;
    }
}
