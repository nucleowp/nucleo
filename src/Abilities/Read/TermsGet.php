<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Content\ContentRefs;
use Nucleo\Content\ContentTypes;
use Nucleo\Content\FieldValues;
use Nucleo\Content\TermItems;
use WP_Taxonomy;
use WP_Term;

/** nucleo/terms-get: tassonomie ammesse e, con taxonomy, i loro termini (docs/contracts/content-items.md, sezione Termini). */
final class TermsGet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/terms-get';
    }

    public function args(): array
    {
        return [
            'label' => __('Get terms', 'nucleo'),
            'description' => 'Returns the taxonomies the content API manages. With "taxonomy", also returns its terms, parents before children. Each term has a "hash" of its content: pass it as "base" to terms-set, which then saves only if the term did not change since.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy slug.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'taxonomies' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'taxonomy' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'hierarchical' => ['type' => 'boolean'],
                                'modeled' => ['type' => 'boolean'],
                                'registeredBy' => ['type' => 'string', 'enum' => ContentTypes::ORIGINS, 'description' => 'Who registers it: nucleo-model (model-set), wordpress, plugin-or-theme (another plugin or the theme: change it there, not with model-set), conflict (declared in the model but registered first by other code, which wins).'],
                                'postTypes' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                        ],
                    ],
                    'terms' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'slug' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'parent' => ['type' => ['string', 'null']],
                                'fields' => ['type' => 'object', 'description' => 'Field values by key.'],
                                'hash' => ['type' => 'string', 'description' => 'Hash of the term as read. Pass it as "base" to terms-set.'],
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
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function execute(array $input): array
    {
        $taxonomies = TermItems::taxonomies();
        if (!isset($input['taxonomy'])) {
            return ['ok' => true, 'taxonomies' => $taxonomies];
        }

        $taxonomy = $input['taxonomy'];
        if (!is_string($taxonomy)) {
            return ['ok' => false, 'error' => ['code' => 'invalid_input', 'message' => __('"taxonomy" must be a string.', 'nucleo')]];
        }
        if (!ContentTypes::allowsTaxonomy($taxonomy)) {
            return ['ok' => false, 'error' => ['code' => 'unknown_taxonomy', 'message' => __('Unknown taxonomy.', 'nucleo')]];
        }

        $terms = array_map(static function (array $term): array {
            $term['hash'] = self::hashOf($term);

            // Senza campi, l'oggetto vuoto va in JSON come {} e non come [].
            if (array_key_exists('fields', $term) && $term['fields'] === []) {
                $term['fields'] = (object) [];
            }

            return $term;
        }, TermItems::get($taxonomy));

        return ['ok' => true, 'taxonomies' => $taxonomies, 'terms' => $terms];
    }

    /**
     * Hash della forma di lettura di un termine: sha1 del JSON, senza id, hash e base.
     *
     * @param array<string,mixed> $term
     */
    public static function hashOf(array $term): string
    {
        unset($term['id'], $term['hash'], $term['base']);

        return sha1((string) wp_json_encode($term));
    }

    /**
     * Forma di lettura di un termine, come in terms-get (slug, name, description, parent, fields).
     *
     * @return array<string,mixed>
     */
    public static function readOf(WP_Term $term): array
    {
        $parent = null;
        if ($term->parent > 0) {
            $found = get_term((int) $term->parent, $term->taxonomy);
            $parent = $found instanceof WP_Term ? $found->slug : null;
        }

        $item = [
            'slug' => $term->slug,
            'name' => $term->name,
            'description' => $term->description,
            'parent' => $parent,
        ];
        $fields = FieldValues::fieldsFor(['taxonomy' => $term->taxonomy]);
        if ($fields !== []) {
            $item['fields'] = ContentRefs::exportFields($fields, FieldValues::get('term', (int) $term->term_id));
        }

        return $item;
    }

    public function permitted(array $input): bool
    {
        if (!current_user_can('edit_posts')) {
            return false;
        }

        $taxonomy = $input['taxonomy'] ?? null;
        if (!is_string($taxonomy)) {
            return true;
        }

        $object = get_taxonomy($taxonomy);

        return !$object instanceof WP_Taxonomy || current_user_can($object->cap->assign_terms);
    }
}
