<?php

declare(strict_types=1);

namespace Nucleo\Seo;

defined('ABSPATH') || exit;

use JsonException;
use Nucleo\Integrations\SeoBridge;

/**
 * JSON-LD (schema.org) della pagina singola in un unico @graph, stampato nel footer: nodi di meta.seo.schema, nodo dell'azienda
 * sulla home, nodi degli script ld+json del contenuto (raccolti dal filtro the_content) e breadcrumb. Spento se Yoast o Rank Math
 * sono attivi, perché stampano già i propri dati. Senza SEO plugin esclude dal sitemap i post con meta noindex.
 */
final class StructuredData
{
    /** Meta del post: '1' se il documento ha meta.seo.noindex. Lo legge registerSitemap(). */
    public const NOINDEX_META = '_nucleo_noindex';

    /** Segnaposto in meta.seo.schema: al suo posto va il nodo dell'azienda (Business). */
    private const BUSINESS_REF = '@business';

    /** Script application/ld+json del contenuto, con la stessa forma di SchemaRegistry. */
    private const PATTERN = '#<script\b[^>]*\btype\s*=\s*(["\'])application/ld\+json\1[^>]*>(.*?)</script\s*>#is';

    /** @var array<string,mixed> meta.seo della richiesta corrente */
    private static array $seo = [];

    private static int $postId = 0;

    /** @var list<array<string,mixed>> nodi raccolti con add() nella richiesta corrente */
    private static array $nodes = [];

    /**
     * Aggiunge un nodo al @graph della pagina corrente.
     * @param array<string,mixed> $node
     */
    public static function add(array $node): void
    {
        self::$nodes[] = $node;
    }

    /**
     * Nodi del @graph della pagina: nodi di schema, azienda se la home o un segnaposto la usa, nodi raccolti, breadcrumb
     * automatico solo se nessun nodo raccolto è un BreadcrumbList.
     * @param array<string,mixed> $seo meta.seo del documento
     * @param list<array<string,mixed>> $nodes nodi raccolti con add()
     * @return list<array<string,mixed>>
     */
    public static function graph(int $postId, array $seo, array $nodes): array
    {
        $permalink = (string) get_permalink($postId);
        $front = self::isFrontPage($postId);

        $graph = [];
        $usesBusiness = false;
        $schema = is_array($seo['schema'] ?? null) ? $seo['schema'] : [];
        foreach ($schema as $node) {
            $node = self::replaceBusiness($node, $usesBusiness);
            if (!is_array($node)) {
                continue;
            }
            $node['url'] ??= $permalink;
            $graph[] = $node;
        }

        if ($front || $usesBusiness) {
            $business = Business::node();
            if ($business !== null) {
                $graph[] = $business;
            }
        }

        array_push($graph, ...$nodes);

        if (!$front && ($seo['breadcrumbs'] ?? null) !== false && !self::hasBreadcrumb($nodes)) {
            $graph[] = self::breadcrumbs($postId);
        }

        return $graph;
    }

    /**
     * Registra la raccolta e la stampa del JSON-LD per la pagina singola. Senza SEO plugin attivo.
     * @param array<string,mixed> $seo meta.seo del documento
     */
    public static function register(int $postId, array $seo): void
    {
        if (SeoBridge::active() !== null) {
            return;
        }

        self::$postId = $postId;
        self::$seo = $seo;
        self::$nodes = [];
        // Priorità 999: il filtro arriva dopo il riempimento degli slot.
        add_filter('the_content', [self::class, 'extract'], 999);
        add_action('wp_footer', [self::class, 'output']);
    }

    /**
     * Filtro the_content: toglie dal contenuto gli script ld+json validi e ne aggiunge i nodi con add().
     * Uno script con @graph aggiunge ciascun nodo del proprio @graph. Gli script non validi restano dove sono.
     */
    public static function extract(string $content): string
    {
        $cleaned = preg_replace_callback(self::PATTERN, static function (array $match): string {
            $value = self::decode($match[2]);
            if ($value === null) {
                return $match[0];
            }
            $graph = $value['@graph'] ?? null;
            $items = is_array($graph) && array_is_list($graph) ? $graph : [$value];
            foreach ($items as $item) {
                if (is_array($item)) {
                    self::add($item);
                }
            }

            return '';
        }, $content);

        return is_string($cleaned) ? $cleaned : $content;
    }

    /** Script ld+json della pagina registrata con register(), se il @graph non è vuoto. Sul wp_footer. */
    public static function output(): void
    {
        $graph = SchemaRegistry::merge(self::graph(self::$postId, self::$seo, self::$nodes), []);
        if ($graph === []) {
            return;
        }

        // Senza flag wp_json_encode escapa "/": "</script>" in un titolo diventa "<\/script>".
        $json = wp_json_encode(['@context' => 'https://schema.org', '@graph' => $graph]);
        if ($json === false) {
            return;
        }

        wp_print_inline_script_tag($json, ['type' => 'application/ld+json']);
    }

    /** Senza SEO plugin attivo: il sitemap dei post non include quelli con meta noindex. */
    public static function registerSitemap(): void
    {
        if (SeoBridge::active() !== null) {
            return;
        }

        add_filter('wp_sitemaps_posts_query_args', [self::class, 'excludeNoindex']);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function excludeNoindex(array $args): array
    {
        $ids = get_posts([
            'post_type' => 'any',
            'meta_key' => self::NOINDEX_META,
            'meta_value' => '1',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'no_found_rows' => true,
        ]);
        $excluded = is_array($args['post__not_in'] ?? null) ? $args['post__not_in'] : [];
        $args['post__not_in'] = array_values(array_unique([...array_map('intval', $excluded), ...array_map('intval', $ids)]));

        return $args;
    }

    /**
     * Sostituisce ogni "@business" (a ogni profondità) con il riferimento al nodo dell'azienda.
     * @param bool $used diventa true se almeno un segnaposto è stato sostituito
     */
    private static function replaceBusiness(mixed $value, bool &$used): mixed
    {
        if ($value === self::BUSINESS_REF) {
            $used = true;

            return ['@id' => home_url('/') . '#business'];
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::replaceBusiness($item, $used);
        }

        return $value;
    }

    /** @return array<string,mixed>|null */
    private static function decode(string $body): ?array
    {
        try {
            $value = json_decode(trim($body), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($value) && !array_is_list($value) ? $value : null;
    }

    /** @param list<array<string,mixed>> $nodes */
    private static function hasBreadcrumb(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (($node['@type'] ?? null) === 'BreadcrumbList') {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private static function breadcrumbs(int $postId): array
    {
        $items = [['name' => (string) get_bloginfo('name'), 'item' => home_url('/')]];
        foreach (array_reverse(get_post_ancestors($postId)) as $ancestor) {
            $items[] = ['name' => (string) get_the_title($ancestor), 'item' => (string) get_permalink($ancestor)];
        }
        $items[] = ['name' => (string) get_the_title($postId), 'item' => (string) get_permalink($postId)];

        $list = [];
        foreach ($items as $index => $entry) {
            $list[] = ['@type' => 'ListItem', 'position' => $index + 1] + $entry;
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
    }

    private static function isFrontPage(int $postId): bool
    {
        return $postId > 0 && get_option('show_on_front') === 'page' && (int) get_option('page_on_front') === $postId;
    }
}
