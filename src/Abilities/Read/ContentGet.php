<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Content\ContentReader;
use Nucleo\Content\ContentTypes;

/** nucleo/content-get: un elemento per tipo e slug (docs/contracts/content-items.md). */
final class ContentGet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/content-get';
    }

    public function args(): array
    {
        return [
            'label' => __('Get content item', 'nucleo'),
            'description' => 'Returns one content item by type and slug, with all its keys and "hash": pass it as "base" to content-upsert, which then saves only if the item did not change since. The item can be sent back to content-upsert as it is (hash, id and protected are ignored). Returns not_found when no item has that slug, read_only_type for product (listed by content-list, never read or written here). For the blocks of a page or post with a Nucleo document (managedBy) use nucleo/doc-get.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['type', 'slug'],
                'properties' => [
                    'type' => ['type' => 'string', 'minLength' => 1, 'description' => 'Content type, for example "post" or "page".'],
                    'slug' => ['type' => 'string', 'minLength' => 1, 'description' => 'Item slug.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'item' => ['type' => 'object', 'description' => 'The complete item, with hash.'],
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
        $type = is_string($input['type'] ?? null) ? $input['type'] : '';
        $slug = is_string($input['slug'] ?? null) ? $input['slug'] : '';

        // content-list elenca anche i prodotti (sola lettura), ma content-get e content-upsert non li gestiscono.
        if ($type !== '' && !ContentTypes::allowsType($type) && ContentTypes::allowsListType($type)) {
            return ['ok' => false, 'error' => ['code' => 'read_only_type', 'message' => __('This content type is only listed: content-get does not read it. Use content-list or doc-preview with contextPostId.', 'nucleo')]];
        }
        $item = $type === '' || $slug === '' ? null : ContentReader::get($type, $slug);
        if ($item === null) {
            return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => __('No item of this type has this slug.', 'nucleo')]];
        }

        $item['hash'] = ContentReader::digest($item);

        return ['ok' => true, 'item' => self::plain($item)];
    }

    public function permitted(array $input): bool
    {
        $type = is_string($input['type'] ?? null) ? $input['type'] : '';
        $object = $type === '' ? null : get_post_type_object($type);

        return $object !== null && current_user_can($object->cap->edit_posts);
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
