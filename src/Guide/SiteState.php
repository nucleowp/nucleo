<?php

declare(strict_types=1);

namespace Nucleo\Guide;

defined('ABSPATH') || exit;

use Nucleo\Content\ModelRepository;
use Nucleo\Layout\PostTypes;
use Nucleo\Services;
use Nucleo\Tokens\TokenSet;

/**
 * Stato del sito per la guida. Poche query aggregate, mai una per documento.
 */
final class SiteState
{
    private const DOC_META = '_nucleo_doc';
    private const DOC_LIMIT = 1000;

    /**
     * @param array<string, int> $tokens chiavi salvate per gruppo di TokenSet
     * @param array<string, int> $layouts layout non cestinati per area (header e footer sempre presenti)
     * @param list<string> $postTypes slug dei tipi del modello
     * @param array<string, int> $documents documenti per tipo, esclusi layout e sezioni
     * @param list<array{ref:string, doc:array<string,mixed>}> $docs tutti i documenti, per DocScan
     * @param array<string, int> $published elementi pubblicati per tipo del modello
     * @param array<string, bool> $singleLayout tipi del modello con un layout single
     * @param array{read:int, total:int, truncated:bool} $coverage
     */
    public function __construct(
        public readonly array $tokens,
        public readonly int $sections,
        public readonly array $layouts,
        public readonly int $menus,
        public readonly array $postTypes,
        public readonly array $documents,
        public readonly array $docs,
        public readonly bool $headerSite,
        public readonly bool $footerSite,
        public readonly array $published,
        public readonly array $singleLayout,
        /** documenti letti per i controlli, totale, e se la lettura si è fermata al limite */
        public readonly array $coverage = ['read' => 0, 'total' => 0, 'truncated' => false],
    ) {
    }

    public static function collect(): self
    {
        $model = ModelRepository::model();
        $types = self::modelTypes($model);
        $map = self::layoutMap();
        $single = is_array($map['single'] ?? null) ? $map['single'] : [];

        [$docs, $documents, $layouts, $coverage] = self::readDocuments();

        $published = [];
        $singleLayout = [];
        foreach ($types as $type) {
            $counts = (array) wp_count_posts($type);
            $published[$type] = (int) ($counts['publish'] ?? 0);
            $singleLayout[$type] = !empty($single['type:' . $type]) || !empty($single['site']);
        }

        return new self(
            tokens: self::savedTokens(),
            sections: self::liveCount(PostTypes::SECTION),
            layouts: $layouts,
            menus: count(wp_get_nav_menus()),
            postTypes: $types,
            documents: $documents,
            docs: $docs,
            headerSite: !empty($map['header']['site'] ?? null),
            footerSite: !empty($map['footer']['site'] ?? null),
            published: $published,
            singleLayout: $singleLayout,
            coverage: $coverage,
        );
    }

    /**
     * Chiavi salvate in nucleo_tokens per ogni gruppo del catalogo (0 se il gruppo manca).
     *
     * @return array<string, int>
     */
    private static function savedTokens(): array
    {
        $stored = get_option('nucleo_tokens', []);
        $stored = is_array($stored) ? $stored : [];

        $tokens = [];
        foreach (array_keys(TokenSet::defaults()->toArray()) as $group) {
            $values = $stored[$group] ?? [];
            $tokens[$group] = is_array($values) ? count($values) : 0;
        }

        return $tokens;
    }

    /** Conteggio dei post non cestinati e non bozze automatiche. */
    private static function liveCount(string $type): int
    {
        $total = 0;
        foreach ((array) wp_count_posts($type) as $status => $n) {
            if (!in_array($status, ['trash', 'auto-draft'], true)) {
                $total += (int) $n;
            }
        }

        return $total;
    }

    /**
     * Due query: conteggi per tipo e documenti non cestinati fino a DOC_LIMIT, in ordine stabile.
     * Ritorna documenti per DocScan, conteggi per tipo (senza layout e sezioni), layout per area e copertura della lettura.
     *
     * @return array{0: list<array{ref:string, doc:array<string,mixed>}>, 1: array<string, int>, 2: array<string, int>, 3: array{read:int, total:int, truncated:bool}}
     */
    private static function readDocuments(): array
    {
        global $wpdb;

        // Conteggi esatti anche oltre il limite di lettura.
        $totals = $wpdb->get_results($wpdb->prepare(
            "SELECT p.post_type, COUNT(*) AS n FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID"
            . " WHERE pm.meta_key = %s AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit') GROUP BY p.post_type",
            self::DOC_META,
        ), ARRAY_A);
        // Ordine stabile: prima layout e sezioni (valgono per molte pagine), poi i più recenti.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_type, p.post_name, pm.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID"
            . " WHERE pm.meta_key = %s AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')"
            . ' ORDER BY p.post_type IN (%s, %s) DESC, p.post_modified_gmt DESC, p.ID DESC LIMIT %d',
            self::DOC_META,
            PostTypes::LAYOUT,
            PostTypes::SECTION,
            self::DOC_LIMIT,
        ), ARRAY_A);

        $docs = [];
        $documents = ['page' => 0, 'post' => 0];
        $layouts = ['header' => 0, 'footer' => 0];
        $total = 0;
        foreach (is_array($totals) ? $totals : [] as $row) {
            $total += (int) $row['n'];
            if (!in_array($row['post_type'], [PostTypes::LAYOUT, PostTypes::SECTION], true)) {
                $documents[(string) $row['post_type']] = (int) $row['n'];
            }
        }

        foreach (is_array($rows) ? $rows : [] as $row) {
            $doc = json_decode((string) $row['meta_value'], true);
            if (!is_array($doc)) {
                continue;
            }
            $type = (string) $row['post_type'];
            // Una bozza non ha ancora lo slug: il riferimento usa l'ID, così ogni copia resta riconoscibile.
            $slug = (string) $row['post_name'] !== '' ? (string) $row['post_name'] : (string) $row['ID'];

            if ($type === PostTypes::LAYOUT) {
                $ref = 'layout:' . $slug;
                $area = $doc['meta']['area'] ?? null;
                if (is_string($area) && $area !== '') {
                    $layouts[$area] = ($layouts[$area] ?? 0) + 1;
                }
            } elseif ($type === PostTypes::SECTION) {
                $ref = 'section:' . $slug;
            } else {
                $ref = $type . ':' . $slug;
            }

            $docs[] = ['ref' => $ref, 'doc' => $doc];
        }

        $read = is_array($rows) ? count($rows) : 0;

        return [$docs, $documents, $layouts, ['read' => $read, 'total' => $total, 'truncated' => $total > $read]];
    }

    /**
     * @param array<string, mixed> $model
     * @return list<string>
     */
    private static function modelTypes(array $model): array
    {
        /** @var list<string> $types */
        $types = [];
        foreach (is_array($model['postTypes'] ?? null) ? $model['postTypes'] : [] as $type) {
            $slug = is_array($type) ? ($type['slug'] ?? null) : null;
            if (is_string($slug) && $slug !== '') {
                $types[] = $slug;
            }
        }

        return array_values(array_unique($types));
    }

    /** @return array<string, mixed> */
    private static function layoutMap(): array
    {
        $artifact = Services::store()->get('layouts') ?? [];

        return is_array($artifact['map'] ?? null) ? $artifact['map'] : [];
    }
}
