<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Blocks\Manifest;
use Nucleo\Docs\Reference;
use Nucleo\Services;

/**
 * nucleo/catalog-get: manifest completo di un blocco, senza percorsi assoluti del server, con chiavi di stile, HTML e CSS di base.
 * Con names restituisce più blocchi (al massimo 20) in forma compatta: chiavi di stile una sola volta, senza CSS di base,
 * entro NAMES_BUDGET caratteri; i nomi esclusi tornano in remaining.
 * Con topic restituisce un argomento di riferimento (Docs\Reference): token, stili, bind, file del sito e altro.
 */
final class CatalogGet implements AbilityDefinition
{
    private const MAX_NAMES = 20;

    /**
     * Caratteri JSON massimi di una risposta con names. Un client MCP rifiuta i risultati oltre circa 25.000 token,
     * e alcuni inviano il risultato due volte (testo e structuredContent): 25.000 caratteri restano lontani dal limite.
     */
    public const NAMES_BUDGET = 25000;

    /** Valore di "number" più frequente nelle chiavi di stile: con names si omette. */
    private const NUMBER_PX = 'a number gets px';

    public function name(): string
    {
        return 'nucleo/catalog-get';
    }

    public function args(): array
    {
        return [
            'label' => __('Block manifest', 'nucleo'),
            'description' => 'With name: full manifest of a Nucleo block (content, settings, supports, allowed children, hints for the AI), the style keys of its groups with CSS property and allowed values, its HTML markup, its base CSS and an example node built from its required content and settings. With names (up to 20): a compact entry per block, without base CSS; the style keys of all their groups appear once in styleKeys. The response stays under 25,000 characters: names that do not fit are listed in remaining, to request in a second call. With topic: reference rules that are not in any manifest: ' . implode(', ', Reference::TOPICS) . '. When the block or topic name is unknown use nucleo/docs-search; the index of blocks is nucleo/catalog-list.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1, 'description' => 'Name of the block, e.g. "nc/heading".'],
                    'names' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => self::MAX_NAMES,
                        'items' => ['type' => 'string', 'minLength' => 1],
                        'description' => 'Names of several blocks, e.g. ["nc/heading", "nc/button"]. Returns one compact entry per name in blocks; names over the size limit go to remaining.',
                    ],
                    'topic' => [
                        'type' => 'string',
                        'enum' => Reference::TOPICS,
                        'description' => 'tokens: groups, keys, units, merge rules. styles: every style key by group. bindings: sources, empty values, repeaters, relations, escape. visibility: breakpoints, visitor and field conditions. data: repeaters, related posts, terms, breadcrumbs. theme: base HTML and CSS, section widths, links. site-files: files of site-import/export and push, import order, dry-run. acknowledge: where it is saved, sections. components: what PHP components can do. layouts: areas, condition types and their format, how a layout is chosen.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'block' => self::blockSchema(),
                    'blocks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['name', 'ok'],
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'ok' => ['type' => 'boolean'],
                                'block' => self::blockSchema(),
                                'error' => ['type' => 'object'],
                            ],
                        ],
                    ],
                    'styleKeys' => ['type' => 'object', 'description' => 'With names: style group → style keys, once for all the blocks. Each block lists its groups in styleGroups. A key without "number" gets px for a number.'],
                    'remaining' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'With names: names left out by the size limit. Request them with a second call.'],
                    'topic' => [
                        'type' => 'object',
                        'properties' => [
                            'topic' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                            'rules' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'data' => ['type' => 'object'],
                        ],
                    ],
                    'error' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function blockSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'version' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'aiHints' => ['type' => 'string'],
                'content' => ['type' => 'object'],
                'settings' => ['type' => 'object'],
                'supports' => ['type' => 'object'],
                'parts' => ['type' => 'object', 'description' => 'Inner parts styled with node.style.parts.<part>: part → CSS selector inside the node. Keys of the groups ' . 'size, spacing, typography, color, border, effects, layout, motion, with states and breakpoints.'],
                'styleKeys' => ['type' => 'object', 'description' => 'Style group → style keys accepted in node.style, with CSS property and allowed values.'],
                'styleGroups' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'With names: style groups of the block; their keys are in the top-level styleKeys.'],
                // anyOf: WordPress accetta uno scalare anche come lista, con oneOf true soddisfa entrambi i rami.
                'children' => ['anyOf' => [['type' => 'boolean'], ['type' => 'array', 'items' => ['type' => 'string']]]],
                'parents' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
                'assets' => ['type' => 'object'],
                'semantics' => ['type' => 'object'],
                'renderer' => ['type' => 'string'],
                'markup' => ['type' => 'object'],
                'css' => ['type' => 'string'],
                'example' => ['type' => 'object', 'description' => 'Minimal valid node: block, and the required content and settings with their default or a placeholder value.'],
            ],
        ];
    }

    public function execute(array $input): array|\WP_Error
    {
        $topic = $input['topic'] ?? null;
        if (is_string($topic) && $topic !== '') {
            return self::topic($topic);
        }

        if (array_key_exists('names', $input)) {
            $names = self::requestedNames($input['names']);
            if ($names === null) {
                /* translators: %d: maximum number of block names in one request. */
                return new \WP_Error('invalid_input', sprintf(__('The "names" field must be a list of 1 to %d block names.', 'nucleo'), self::MAX_NAMES));
            }

            return self::many(Services::registry(), $names);
        }

        $name = $input['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return new \WP_Error('invalid_input', __('The "name", "names" or "topic" field is required.', 'nucleo'));
        }

        $manifest = Services::registry()->get($name);
        if ($manifest === null) {
            return ['ok' => false, 'error' => self::notFound($name)];
        }

        return ['ok' => true, 'block' => self::block($manifest)];
    }

    /**
     * Risposta di una richiesta con names: blocchi compatti nell'ordine chiesto finché la risposta resta entro
     * NAMES_BUDGET caratteri; il primo blocco entra sempre. Le chiavi di stile dei gruppi usati compaiono una volta.
     * @param list<string> $names
     * @return array<string,mixed>
     */
    public static function many(\Nucleo\Blocks\Registry $registry, array $names): array
    {
        $blocks = [];
        $groups = [];
        $remaining = [];
        $size = 0;
        foreach ($names as $name) {
            if ($remaining !== []) {
                $remaining[] = $name;
                continue;
            }
            $manifest = $registry->get($name);
            $entry = $manifest === null
                ? ['name' => $name, 'ok' => false, 'error' => self::notFound($name)]
                : ['name' => $name, 'ok' => true, 'block' => self::compactBlock($manifest)];
            $added = $manifest === null ? [] : array_values(array_diff(self::styleGroups($manifest), $groups));
            // Reference::styleKeys([]) darebbe tutti i gruppi: senza gruppi nuovi il costo è zero.
            $cost = strlen((string) wp_json_encode($entry)) + ($added === [] ? 0 : strlen((string) wp_json_encode(self::compactStyleKeys($added))));
            // 1.000 caratteri restano a involucro e remaining (20 nomi al massimo).
            if ($blocks !== [] && $size + $cost > self::NAMES_BUDGET - 1000) {
                $remaining[] = $name;
                continue;
            }
            $blocks[] = $entry;
            $groups = [...$groups, ...$added];
            $size += $cost;
        }

        $out = [
            'ok' => true,
            'blocks' => $blocks,
            'styleKeys' => $groups === [] ? new \stdClass() : (object) self::compactStyleKeys($groups),
        ];
        if ($remaining !== []) {
            $out['remaining'] = $remaining;
        }

        return $out;
    }

    /**
     * Chiavi di stile dei gruppi per names: senza "number" quando vale il caso comune (un numero riceve px).
     * @param non-empty-list<string> $groups
     * @return array<string,list<array<string,mixed>>>
     */
    private static function compactStyleKeys(array $groups): array
    {
        $out = [];
        foreach (Reference::styleKeys($groups) as $group => $keys) {
            foreach ($keys as $key) {
                if ($key['number'] === self::NUMBER_PX) {
                    unset($key['number']);
                }
                $out[$group][] = $key;
            }
        }

        return $out;
    }

    /**
     * Nomi di blocchi di una richiesta con names: lista non vuota di stringhe non vuote, senza duplicati, al massimo 20.
     * @return list<string>|null null se la forma non è valida
     */
    public static function requestedNames(mixed $names): ?array
    {
        if (!is_array($names) || $names === [] || count($names) > self::MAX_NAMES || !array_is_list($names)) {
            return null;
        }
        $out = [];
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                return null;
            }
            $out[] = $name;
        }

        return array_values(array_unique($out));
    }

    /**
     * Esempio di nodo minimo: il block e, per ogni contenuto e impostazione obbligatori, il default o un valore segnaposto.
     * @param array<string,array<string,mixed>> $content
     * @param array<string,array<string,mixed>> $settings
     * @return array<string,mixed>
     */
    public static function example(string $name, array $content, array $settings): array
    {
        return [
            'block' => $name,
            'content' => (object) self::minimal($content),
            'settings' => (object) self::minimal($settings),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $schema
     * @return array<string,mixed>
     */
    private static function minimal(array $schema): array
    {
        $out = [];
        foreach ($schema as $key => $property) {
            if (($property['required'] ?? false) !== true) {
                continue;
            }
            $out[$key] = array_key_exists('default', $property)
                ? $property['default']
                : self::placeholder(is_string($property['type'] ?? null) ? $property['type'] : 'string');
        }

        return $out;
    }

    private static function placeholder(string $type): mixed
    {
        return match ($type) {
            'integer', 'number' => 0,
            'boolean' => false,
            'array' => [],
            'object' => new \stdClass(),
            default => '',
        };
    }

    /** @return array{code:string,message:string} */
    private static function notFound(string $name): array
    {
        /* translators: %s: name of the block that was requested. */
        return ['code' => 'not_found', 'message' => sprintf(__('Block not found: %s.', 'nucleo'), $name)];
    }

    /** @return array<string,mixed> */
    private static function block(Manifest $manifest): array
    {
        return [
            'name' => $manifest->name,
            'version' => $manifest->version,
            'title' => $manifest->title,
            'category' => $manifest->category,
            'description' => $manifest->description,
            'aiHints' => $manifest->aiHints,
            'content' => $manifest->content,
            'settings' => $manifest->settings,
            'supports' => $manifest->supports,
            'parts' => (object) $manifest->parts,
            'children' => $manifest->children,
            'parents' => $manifest->parents,
            'assets' => $manifest->assets,
            'semantics' => $manifest->semantics,
            'renderer' => $manifest->renderer,
            // Senza gruppi nessuna chiave (Reference::styleKeys([]) le darebbe tutte); sempre un oggetto in JSON.
            'styleKeys' => self::styleGroups($manifest) === [] ? new \stdClass() : (object) Reference::styleKeys(self::styleGroups($manifest)),
            'markup' => (object) self::markup($manifest->name),
            'css' => self::blockCss($manifest),
            'example' => self::example($manifest->name, $manifest->content, $manifest->settings),
        ];
    }

    /**
     * Blocco per names: senza CSS di base, renderer e asset, che servono poco a comporre un documento;
     * al posto delle chiavi di stile, i nomi dei gruppi. Con name restano disponibili tutti i campi.
     * @return array<string,mixed>
     */
    private static function compactBlock(Manifest $manifest): array
    {
        $block = self::block($manifest);
        unset($block['styleKeys'], $block['css'], $block['renderer'], $block['assets']);
        $block['styleGroups'] = self::styleGroups($manifest);

        return $block;
    }

    /** @return array<string,mixed> */
    private static function topic(string $topic): array
    {
        $tokens = $topic === 'tokens' ? Services::tokens()->toArray() : [];
        $css = $topic === 'theme' ? self::themeCss() : '';
        $groups = [];
        if ($topic === 'styles') {
            foreach (Services::registry()->all() as $manifest) {
                $groups[$manifest->name] = self::styleGroups($manifest);
            }
            ksort($groups);
        }
        $doc = Reference::topic($topic, $tokens, $css, $groups);
        if ($doc === null) {
            /* translators: 1: topic requested, 2: list of topics. */
            return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => sprintf(__('Topic not found: %1$s. Topics: %2$s.', 'nucleo'), $topic, implode(', ', Reference::TOPICS))]];
        }
        $doc['data'] = (object) $doc['data'];

        return ['ok' => true, 'topic' => $doc];
    }

    /** @return list<string> */
    private static function styleGroups(Manifest $manifest): array
    {
        $groups = $manifest->supports['style'] ?? [];

        return is_array($groups) ? array_values(array_filter($groups, 'is_string')) : [];
    }

    /**
     * HTML prodotto dal blocco (data/block-markup.json): html, css, dynamic. Vuoto se il blocco non è descritto.
     * @return array<string,mixed>
     */
    private static function markup(string $name): array
    {
        $file = dirname(__DIR__, 3) . '/data/block-markup.json';
        $raw = is_readable($file) ? file_get_contents($file) : false;
        $all = is_string($raw) ? json_decode($raw, true) : null;
        $entry = is_array($all) ? ($all[$name] ?? null) : null;

        return is_array($entry) ? $entry : [];
    }

    /** CSS di base del blocco (style.css del manifest), vuoto se il blocco non ne ha. */
    private static function blockCss(Manifest $manifest): string
    {
        $style = $manifest->assets['style'] ?? null;
        if (!is_string($style) || $style === '') {
            return '';
        }
        $file = $manifest->dir . '/' . $style;
        $css = is_readable($file) ? file_get_contents($file) : false;

        return is_string($css) ? $css : '';
    }

    /** base.css del tema attivo se è nucleo-canvas; vuoto con un altro tema. */
    private static function themeCss(): string
    {
        $file = get_template_directory() . '/base.css';
        if (get_template() !== 'nucleo-canvas' || !is_readable($file)) {
            return '';
        }
        $css = file_get_contents($file);

        return is_string($css) ? $css : '';
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_posts');
    }
}
