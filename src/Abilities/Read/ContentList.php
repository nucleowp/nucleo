<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Content\ContentReader;
use Nucleo\Content\ContentTypes;

/** nucleo/content-list: elenco paginato degli elementi dei tipi di contenuto (docs/contracts/content-items.md). */
final class ContentList implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/content-list';
    }

    public function args(): array
    {
        return [
            'label' => __('List content items', 'nucleo'),
            'description' => 'Lists content items of the allowed content types, paginated. Without "full" each item is a summary with id (pass it as contextPostId to doc-preview). With full each item is complete and has "hash": pass it as "base" to content-upsert, which then saves only if the item did not change since; the item can be sent back as it is (hash, id and protected are ignored). product items are listed read-only: content-get and content-upsert do not handle them. Trashed items are never listed.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'description' => 'Content type, for example "post" or "page". Omit to list all allowed types. "product" lists WooCommerce products, read only: content-upsert does not write them.'],
                    'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'private', 'future', 'pending'], 'description' => 'Only items with this status. Default: all but trash.'],
                    'term' => ['type' => 'string', 'description' => 'Only items with this term, as "<taxonomy>/<slug>".'],
                    'search' => ['type' => 'string', 'description' => 'Text to search in titles and content.'],
                    'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number. Default 1.'],
                    'perPage' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Items per page, from 1 to 100. Default 20.'],
                    'full' => ['type' => 'boolean', 'description' => 'Return complete items instead of summaries. Default false.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'items' => [
                        'type' => 'array',
                        'description' => 'Items the user can edit. With "full" they are complete items, otherwise summaries.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer', 'description' => 'Post id, only in summaries. For example contextPostId of doc-preview.'],
                                'type' => ['type' => 'string'],
                                'slug' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'status' => ['type' => 'string'],
                                'date' => ['type' => 'string'],
                                'fields' => ['type' => 'object'],
                                'hash' => ['type' => 'string', 'description' => 'Only with "full": fingerprint of the item, to send as "base" to content-upsert.'],
                                'managedBy' => ['type' => 'string'],
                                'protected' => ['type' => 'boolean', 'description' => 'System page, not trashable.'],
                            ],
                        ],
                    ],
                    'skipped' => [
                        'type' => 'array',
                        'description' => 'Items left out of "items".',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string'],
                                'id' => ['type' => 'integer'],
                                'slug' => ['type' => ['string', 'null']],
                                'code' => ['type' => 'string', 'enum' => ['missing_slug', 'ambiguous_slug']],
                                'message' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'total' => ['type' => 'integer'],
                    'page' => ['type' => 'integer'],
                    'pages' => ['type' => 'integer'],
                    'types' => [
                        'type' => 'array',
                        'description' => 'Allowed content types.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'hierarchical' => ['type' => 'boolean'],
                                'modeled' => ['type' => 'boolean'],
                                'registeredBy' => ['type' => 'string', 'enum' => ContentTypes::ORIGINS, 'description' => 'Who registers it: nucleo-model (model-set), wordpress, plugin-or-theme (another plugin or the theme: change it there, not with model-set), conflict (declared in the model but registered first by other code, which wins).'],
                                'taxonomies' => ['type' => 'array', 'items' => ['type' => 'string']],
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
        $type = $input['type'] ?? null;
        if (is_string($type) && $type !== '' && !ContentTypes::allowsListType($type)) {
            return ['ok' => false, 'error' => ['code' => 'unknown_type', 'message' => __('This content type is not allowed.', 'nucleo')]];
        }

        $result = ContentReader::list($input);

        return [
            'ok' => true,
            'items' => array_map(static fn (array $item): array => self::plain($item), $result['items']),
            'skipped' => $result['skipped'] ?? [],
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'types' => ContentReader::types(),
        ];
    }

    public function permitted(array $input): bool
    {
        $type = $input['type'] ?? null;
        if (is_string($type) && $type !== '' && ContentTypes::allowsType($type)) {
            $object = get_post_type_object($type);

            return $object !== null && current_user_can($object->cap->edit_posts);
        }
        if (is_string($type) && $type !== '') {
            return current_user_can('edit_posts');
        }

        // Senza type: basta edit_posts su almeno un tipo ammesso.
        foreach (ContentTypes::types() as $name) {
            $object = get_post_type_object($name);
            if ($object !== null && current_user_can($object->cap->edit_posts)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Campi e termini vuoti diventano {} nel JSON, non [] (docs/contracts/content-items.md).
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function plain(array $item): array
    {
        foreach (['fields', 'terms'] as $key) {
            if (($item[$key] ?? null) === []) {
                $item[$key] = (object) [];
            }
        }

        return $item;
    }
}
