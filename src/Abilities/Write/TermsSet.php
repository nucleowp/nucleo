<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Abilities\Read\TermsGet;
use Nucleo\Content\ContentTypes;
use Nucleo\Content\TermItems;
use WP_Taxonomy;
use WP_Term;

/** nucleo/terms-set: crea o aggiorna i termini di una tassonomia per slug, in modo parziale. Non cancella (docs/contracts/content-items.md, sezione Termini). */
final class TermsSet implements AbilityDefinition
{
    private const MAX_TERMS = 500;

    public function name(): string
    {
        return 'nucleo/terms-set';
    }

    public function args(): array
    {
        return [
            'label' => __('Save terms', 'nucleo'),
            'description' => 'Creates or updates the terms of a taxonomy by slug. Partial: keys left out are kept. Terms are never deleted. A term identical to the saved one is not written and reports action "unchanged". A term with errors writes nothing; the others go on. A term with "base" (the hash from terms-get) that changed since is not saved: error code "conflict".',
            'input_schema' => [
                'type' => 'object',
                'required' => ['taxonomy', 'terms'],
                'properties' => [
                    'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug.'],
                    'terms' => [
                        'type' => 'array',
                        'maxItems' => self::MAX_TERMS,
                        'description' => 'Terms by slug: name (required on creation), description, parent (slug), fields, base (hash read with terms-get: the term is saved only if it did not change since).',
                        'items' => ['type' => 'object'],
                    ],
                    'dryRun' => ['type' => 'boolean', 'description' => 'Checks the terms without writing. Results have the expected action and a null id.'],
                    'media' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string'],
                        'description' => 'Map from file name to URL, from the manifest.',
                    ],
                    'pending' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Symbols the push will create, such as term:<taxonomy>/<slug> or media:<file>. Used with dryRun.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'created' => ['type' => 'integer'],
                    'updated' => ['type' => 'integer'],
                    'unchanged' => ['type' => 'integer', 'description' => 'Existing terms identical to the input: not written.'],
                    'results' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'index' => ['type' => 'integer'],
                                'slug' => ['type' => 'string'],
                                'action' => ['type' => 'string', 'enum' => ['created', 'updated', 'unchanged']],
                                'id' => ['type' => ['integer', 'null']],
                                'hash' => ['type' => 'string', 'description' => 'Hash of the saved term: the new "base" for the next terms-set. Absent in dryRun.'],
                            ],
                        ],
                    ],
                    'errors' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'index' => ['type' => 'integer'],
                                'slug' => ['type' => 'string'],
                                'path' => ['type' => 'string'],
                                'code' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                            ],
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
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function execute(array $input): array
    {
        $taxonomy = $input['taxonomy'] ?? null;
        $terms = $input['terms'] ?? null;
        if (!is_string($taxonomy) || !is_array($terms) || !array_is_list($terms)) {
            return ['ok' => false, 'error' => ['code' => 'invalid_input', 'message' => __('"taxonomy" must be a string and "terms" a list.', 'nucleo')]];
        }
        if (count($terms) > self::MAX_TERMS) {
            return ['ok' => false, 'error' => ['code' => 'invalid_input', 'message' => __('At most 500 terms per call.', 'nucleo')]];
        }

        $dryRun = $input['dryRun'] ?? false;
        $media = $input['media'] ?? [];
        $pending = $input['pending'] ?? [];
        if (
            !is_bool($dryRun)
            || !is_array($media) || array_filter($media, static fn (mixed $url): bool => !is_string($url)) !== []
            || !is_array($pending) || !array_is_list($pending) || array_filter($pending, static fn (mixed $symbol): bool => !is_string($symbol)) !== []
        ) {
            return ['ok' => false, 'error' => ['code' => 'invalid_input', 'message' => __('"dryRun" must be a boolean, "media" an object of strings and "pending" a list of strings.', 'nucleo')]];
        }

        [$stale, $skip] = self::stale($taxonomy, $terms);
        $send = [];
        $origin = [];
        foreach ($terms as $index => $term) {
            if (in_array($index, $skip, true)) {
                continue;
            }
            $send[] = $term;
            $origin[] = $index;
        }

        // TermItems numera i termini inviati: gli indici tornano a quelli della chiamata.
        $report = TermItems::set($taxonomy, $send, $dryRun, $media, $pending);
        foreach ($report['errors'] as $i => $error) {
            if ($error['index'] >= 0) {
                $report['errors'][$i]['index'] = $origin[$error['index']];
            }
        }
        foreach ($report['results'] as $i => $result) {
            $report['results'][$i]['index'] = $origin[$result['index']];
        }
        $report['errors'] = array_merge($report['errors'], $stale);
        usort($report['errors'], static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        foreach ($report['results'] as $i => $result) {
            $saved = $result['id'] === null ? null : get_term($result['id']);
            if ($saved instanceof WP_Term) {
                $report['results'][$i]['hash'] = TermsGet::hashOf(TermsGet::readOf($saved));
            }
        }

        return ['ok' => $report['errors'] === []] + $report;
    }

    public function permitted(array $input): bool
    {
        $taxonomy = $input['taxonomy'] ?? null;
        if (!is_string($taxonomy)) {
            return false;
        }

        $object = get_taxonomy($taxonomy);

        return $object instanceof WP_Taxonomy && current_user_can($object->cap->manage_terms);
    }

    /**
     * Termini esistenti il cui hash non è più il base ricevuto: non si scrivono. Ritorna gli errori e gli indici da togliere
     * dalla chiamata. Un termine nuovo, senza base o con base non stringa non entra qui (il base non stringa è un errore).
     *
     * @param list<mixed> $terms
     * @return array{0:list<array<string,mixed>>,1:list<int>}
     */
    private static function stale(string $taxonomy, array $terms): array
    {
        if (!ContentTypes::allowsTaxonomy($taxonomy)) {
            return [[], []];
        }

        $errors = [];
        $skip = [];
        foreach ($terms as $index => $term) {
            if (!is_array($term) || !array_key_exists('base', $term)) {
                continue;
            }

            $slug = is_string($term['slug'] ?? null) ? $term['slug'] : '';
            if (!is_string($term['base'])) {
                $errors[] = ['index' => $index, 'slug' => $slug, 'path' => '/base', 'code' => 'invalid_value', 'message' => __('Invalid value.', 'nucleo')];
                $skip[] = $index;
                continue;
            }

            $existing = $slug === '' ? null : get_term_by('slug', $slug, $taxonomy);
            if (!$existing instanceof WP_Term || TermsGet::hashOf(TermsGet::readOf($existing)) === $term['base']) {
                continue;
            }

            $errors[] = ['index' => $index, 'slug' => $slug, 'path' => '', 'code' => 'conflict', 'message' => __('The term changed on the site after it was read. Via MCP: read it again with terms-get and resend it with the new hash as base, or without base to overwrite. With the CLI: nucleo pull keeps the site version, nucleo push --force with the terms file overwrites it.', 'nucleo')];
            $skip[] = $index;
        }

        return [$errors, $skip];
    }
}
