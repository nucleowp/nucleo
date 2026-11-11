<?php

declare(strict_types=1);

namespace Nucleo\Guide;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Core\ButtonRenderer;
use Nucleo\Tokens\TokenMatcher;

/**
 * Lettura dei documenti per la guida: conta i segnali di R1-R4 descritti in ScanResult.
 * Lavora solo sugli array dei documenti, senza query.
 */
final class DocScan
{
    /** Contatori, con i nomi delle proprietà di ScanResult. */
    private const COUNTERS = [
        'tokenLiterals', 'repeated', 'globalParts', 'pathLinks', 'handLists',
        'inertButtons', 'forms', 'lists', 'sectionRefs', 'mixedSections', 'distinct',
    ];
    private const REPEAT_MIN_SIZE = 5;
    private const REPEAT_MIN_COUNT = 2;
    private const HAND_MIN_SIZE = 2;
    private const HAND_MIN_GROUP = 3;
    private const SAMPLE_MAX = 3;
    private const REF = 'nc/section-ref';
    /** Sorgenti che leggono un campo del contenuto corrente (post, scheda o riga). */
    private const ITEM_SOURCES = ['post', 'nucleo', 'item', 'acf', 'metabox', 'jetengine', 'woo'];

    /** @var array<string, int> */
    private array $counts = [];
    /** @var array<string, list<string>> */
    private array $samples = [];
    /** @var list<array{hash:string, size:int, parent:?int, ref:string, id:string, excluded:bool, distinct:bool, outline:array<string,int>}> */
    private array $occurrences = [];
    /** @var list<array{nodes:list<string>, size:int, outline:string}> */
    private array $groups = [];
    /**
     * Tipo del root di ogni sezione per slug: un nc/section-ref di una sezione nc/section conta come sezione (R1.7).
     * @var array<string,string>
     */
    private array $sectionRoots = [];
    private bool $globalHit = false;

    /**
     * @param array<string, array<string, mixed>> $defaults blocco → impostazione → valore di default del manifest:
     *        un nc/heading senza level ha la forma del default, non una forma sua
     */
    public function __construct(private readonly TokenMatcher $matcher, private readonly array $defaults = [])
    {
    }

    /** @param list<array{ref:string, doc:array<string,mixed>}> $docs */
    public function scan(array $docs): ScanResult
    {
        $this->counts = array_fill_keys(self::COUNTERS, 0);
        $this->samples = [];
        $this->occurrences = [];
        $this->groups = [];
        $this->sectionRoots = [];
        foreach ($docs as $entry) {
            if (str_starts_with($entry['ref'], 'section:') && is_string($entry['doc']['root']['type'] ?? null)) {
                $this->sectionRoots[substr($entry['ref'], 8)] = $entry['doc']['root']['type'];
            }
        }

        foreach ($docs as $entry) {
            $this->scanDoc($entry['ref'], $entry['doc']);
        }
        $this->countRepeated();

        return new ScanResult(
            tokenLiterals: $this->counts['tokenLiterals'],
            repeated: $this->counts['repeated'],
            globalParts: $this->counts['globalParts'],
            pathLinks: $this->counts['pathLinks'],
            handLists: $this->counts['handLists'],
            inertButtons: $this->counts['inertButtons'],
            forms: $this->counts['forms'],
            lists: $this->counts['lists'],
            sectionRefs: $this->counts['sectionRefs'],
            samples: $this->samples,
            groups: $this->groups,
            mixedSections: $this->counts['mixedSections'],
            distinct: $this->counts['distinct'],
        );
    }

    /** @param array<string, mixed> $doc */
    private function scanDoc(string $ref, array $doc): void
    {
        $root = $doc['root'] ?? null;
        if (!is_array($root)) {
            return;
        }

        $this->globalHit = false;
        $this->walk($root, $ref, null, false);

        if (($doc['kind'] ?? 'page') === 'page' && $this->hasGlobalPart()) {
            $this->hit('globalParts', $ref);
        }
    }

    /**
     * Visita il nodo e i suoi figli. Restituisce hash di struttura, dimensione per R1.5, indice,
     * dimensione per le liste a mano e sagoma (etichetta → numero di nodi).
     *
     * @param array<string, mixed> $node
     * @return array{0:string, 1:int, 2:int, 3:int, 4:array<string,int>}
     */
    private function walk(array $node, string $ref, ?int $parent, bool $underQuery): array
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
        $style = is_array($node['style'] ?? null) ? $node['style'] : [];
        $id = $this->nodeId($node);
        $children = array_values(array_filter(
            is_array($node['children'] ?? null) ? $node['children'] : [],
            'is_array',
        ));

        if ($type === 'nc/menu' || in_array($settings['tag'] ?? null, ['header', 'footer'], true)) {
            $this->globalHit = true;
        }
        $this->countNode($type, $settings, $style, $ref, $id, isset($node['bind']['settings.href']));

        // Posto riservato prima dei figli, così il genitore ha il suo indice.
        $index = count($this->occurrences);
        // Elenchi e i loro nodi leggono già contenuti veri: non contano come ripetizioni.
        $isList = in_array($type, ['nc/query', 'nc/collection'], true);
        // "guide": {"distinct": "motivo"} dichiara il blocco diverso di proposito: il nodo non conta come ripetizione,
        // i blocchi al suo interno sì. Lo stato della guida elenca questi nodi con il motivo, per chi controlla.
        $reason = is_string($node['guide']['distinct'] ?? null) ? trim($node['guide']['distinct']) : '';
        $distinct = $reason !== '';
        if ($distinct) {
            $this->hit('distinct', $ref . '#' . $id . ': ' . $reason);
        }
        $this->occurrences[] = ['hash' => '', 'size' => 0, 'parent' => $parent, 'ref' => $ref, 'id' => $id, 'excluded' => $underQuery || $isList, 'distinct' => $distinct, 'outline' => []];

        // I figli hanno tra i loro antenati questo nodo: vale per handLists.
        $childUnder = $underQuery || $isList;
        $shapes = [];
        foreach ($children as $child) {
            $shapes[] = $this->walk($child, $ref, $index, $childUnder);
        }
        if (!$childUnder) {
            $this->countHandLists($children, $shapes, $ref);
        }
        if ($type === 'nc/section') {
            $this->countMixed($children, $ref, $id);
        }

        // Forma del blocco: tipi, livelli dei titoli, layout, tag e sezioni inserite. I collegamenti a un campo ne fanno parte:
        // quattro riquadri uguali che leggono quattro campi diversi non sono ripetizioni. Testi, immagini, link e stile no:
        // diventano parametri della sezione.
        $label = $this->label($type, $settings);
        // Un blocco diverso di proposito rende diversi anche i suoi contenitori.
        $hash = sha1($label . ($distinct ? '!' . $ref . '#' . $id : '') . self::bindings($node) . '(' . implode(',', array_column($shapes, 0)) . ')');
        // Un nc/section-ref e un elenco sono già riusati: pesano 0, e un contenitore di soli riferimenti non è una ripetizione.
        $weightless = $type === self::REF || $isList;
        $size = $weightless ? 0 : 1 + array_sum(array_column($shapes, 1));
        $handSize = 1 + array_sum(array_column($shapes, 3));
        // Sagoma con gli stessi pesi di $size: i nodi che pesano 0 hanno la chiave con "~" e non contano nel numero.
        $outline = $weightless ? ['~' . $label => 1] : [$label => 1];
        foreach ($weightless ? [] : array_column($shapes, 4) as $inner) {
            foreach ($inner as $l => $n) {
                $outline[$l] = ($outline[$l] ?? 0) + $n;
            }
        }
        $this->occurrences[$index] = ['hash' => $hash, 'size' => $size, 'outline' => $outline] + $this->occurrences[$index];

        return [$hash, $size, $index, $handSize, $outline];
    }

    /**
     * Etichetta di forma: tipo e impostazioni che cambiano la struttura (nc/heading h2, nc/container grid, nc/section-ref titolo).
     * @param array<string, mixed> $settings
     */
    private function label(string $type, array $settings): string
    {
        $settings += $this->defaults[$type] ?? [];
        $keys = match ($type) {
            self::REF => ['section'],
            'nc/heading' => ['level'],
            'nc/query', 'nc/collection' => ['postType'],
            default => ['layout', 'tag'],
        };
        $parts = [$type];
        foreach ($keys as $key) {
            $value = $settings[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                $parts[] = ($key === 'level' ? 'h' : '') . $value;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * R1.7: una nc/section con figli diretti misti (sezioni e altri blocchi) manda gli altri blocchi ai bordi.
     * @param list<array<string, mixed>> $children
     */
    private function countMixed(array $children, string $ref, string $id): void
    {
        $sections = 0;
        $known = 0;
        foreach ($children as $child) {
            $type = $child['type'] ?? null;
            if ($type === self::REF) {
                $type = $this->sectionRoots[(string) ($child['settings']['section'] ?? '')] ?? null;
                if ($type === null) {
                    // Sezione assente o non letta: non si sa cosa disegna, non conta né da una parte né dall'altra.
                    continue;
                }
            }
            $known++;
            $sections += $type === 'nc/section' ? 1 : 0;
        }
        if ($sections > 0 && $sections < $known) {
            $this->hit('mixedSections', $ref . '#' . $id);
        }
    }

    /**
     * Campi dell'elemento che il nodo legge, come "source:field" ordinati; vuota senza collegamenti a un campo.
     * Contano solo le sorgenti che leggono un campo del contenuto corrente: param, site, author e simili no,
     * e fallback non conta. Così una scheda a mano con fallback o parametri diversi resta una ripetizione.
     *
     * @param array<string, mixed> $node
     */
    private static function bindings(array $node): string
    {
        $fields = [];
        foreach (is_array($node['bind'] ?? null) ? $node['bind'] : [] as $bind) {
            if (is_array($bind) && in_array($bind['source'] ?? null, self::ITEM_SOURCES, true) && is_string($bind['field'] ?? null)) {
                $fields[] = $bind['source'] . ':' . $bind['field'];
            }
        }
        if ($fields === []) {
            return '';
        }
        $fields = array_unique($fields);
        sort($fields);

        return '[' . implode(',', $fields) . ']';
    }

    /**
     * @param list<array<string, mixed>> $children
     * @param list<array{0:string, 1:int, 2:int, 3:int, 4:array<string,int>}> $shapes
     */
    private function countHandLists(array $children, array $shapes, string $ref): void
    {
        $groups = [];
        foreach ($shapes as $i => [$hash, $_s, $_i, $size]) {
            if ($size >= self::HAND_MIN_SIZE) {
                $groups[$hash][] = $i;
            }
        }
        foreach ($groups as $members) {
            if (count($members) >= self::HAND_MIN_GROUP) {
                $this->hit('handLists', $ref . '#' . $this->nodeId($children[$members[0]]));
                // Le schede di una lista a mano sono già un compito della fase 3 (R3.1): non contano anche in R1.5.
                foreach ($members as $i) {
                    $this->occurrences[$shapes[$i][2]]['excluded'] = true;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $style
     */
    private function countNode(string $type, array $settings, array $style, string $ref, string $id, bool $boundHref = false): void
    {
        $at = $ref . '#' . $id;

        foreach ($style as $prop => $value) {
            foreach (is_array($value) ? $value : [$value] as $literal) {
                if (is_string($literal) && $this->matcher->match((string) $prop, $literal) !== null) {
                    $this->hit('tokenLiterals', $at);
                }
            }
        }

        $href = $settings['href'] ?? null;
        if (is_string($href) && str_starts_with($href, '/') && !str_starts_with($href, '//')) {
            $this->hit('pathLinks', $at);
        }
        if ($type === 'nc/button' && !$boundHref && in_array(ButtonRenderer::safeHref($href), [null, '#'], true)) {
            $this->hit('inertButtons', $at);
        }

        if ($type === 'nc/native-form') {
            $this->hit('forms', $at);
        }
        if (in_array($type, ['nc/query', 'nc/collection'], true)) {
            $this->hit('lists', $at);
        }
        if ($type === 'nc/section-ref') {
            $this->hit('sectionRefs', $at);
        }
    }

    /**
     * Sottoalberi ripetuti: conta ogni struttura una volta, al livello più alto.
     */
    private function countRepeated(): void
    {
        // L'esclusione passa ai discendenti: i genitori precedono sempre i figli nell'elenco.
        foreach ($this->occurrences as $i => $occ) {
            if ($occ['parent'] !== null && $this->occurrences[$occ['parent']]['excluded']) {
                $this->occurrences[$i]['excluded'] = true;
            }
        }
        // Un nodo distinct non conta, i suoi figli sì.
        $counted = array_values(array_filter($this->occurrences, static fn (array $o): bool => !$o['excluded'] && !$o['distinct']));

        $totals = [];
        foreach ($counted as $occ) {
            $totals[$occ['hash']] = ($totals[$occ['hash']] ?? 0) + 1;
        }

        $repeated = [];
        foreach ($counted as $occ) {
            if ($occ['size'] >= self::REPEAT_MIN_SIZE && $totals[$occ['hash']] >= self::REPEAT_MIN_COUNT) {
                $repeated[$occ['hash']] = true;
            }
        }

        // Una forma si segnala se almeno una copia non sta dentro un blocco già ripetuto; il gruppo elenca tutte le copie,
        // anche quelle annidate (W con H dentro nelle pagine A e C, H da solo in B: H si segnala con tre copie).
        $copies = [];
        $first = [];
        foreach ($counted as $occ) {
            if (!isset($repeated[$occ['hash']])) {
                continue;
            }
            $copies[$occ['hash']][] = $occ;
            $parent = $occ['parent'];
            $nested = $parent !== null && isset($repeated[$this->occurrences[$parent]['hash']]);
            if (!$nested && !isset($first[$occ['hash']])) {
                $first[$occ['hash']] = $occ;
            }
        }

        foreach ($first as $hash => $top) {
            $this->hit('repeated', $top['ref'] . '#' . $top['id']);
            $weighted = [];
            $weightless = [];
            foreach ($top['outline'] as $label => $n) {
                $text = ltrim($label, '~') . ($n > 1 ? ' ×' . $n : '');
                if (str_starts_with($label, '~')) {
                    $weightless[] = $text;
                } else {
                    $weighted[] = $text;
                }
            }
            $this->groups[] = [
                'nodes' => array_map(static fn (array $o): string => $o['ref'] . '#' . $o['id'], $copies[$hash]),
                'size' => $top['size'],
                'outline' => implode(', ', $weighted) . ($weightless === [] ? '' : '; ' . implode(', ', $weightless) . ' (0)'),
            ];
        }
    }

    private function hasGlobalPart(): bool
    {
        return $this->globalHit;
    }

    private function hit(string $counter, string $sample): void
    {
        $this->counts[$counter]++;
        if (count($this->samples[$counter] ?? []) < self::SAMPLE_MAX) {
            $this->samples[$counter][] = $sample;
        }
    }

    /** @param array<string, mixed> $node */
    private function nodeId(array $node): string
    {
        return is_scalar($node['id'] ?? null) ? (string) $node['id'] : '';
    }
}
