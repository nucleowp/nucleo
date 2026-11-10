<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Content\ContentWriter;
use Nucleo\OAuth\CurrentToken;
use Nucleo\OAuth\Scopes;

/** nucleo/content-upsert: crea, aggiorna o cestina elementi per tipo e slug (docs/contracts/content-items.md). */
final class ContentUpsert implements AbilityDefinition
{
    private const MAX_ITEMS = 100;

    public function name(): string
    {
        return 'nucleo/content-upsert';
    }

    public function args(): array
    {
        return [
            'label' => __('Create or update content items', 'nucleo'),
            'description' => 'Creates or updates content items by type and slug, and trashes items with status "trash". An item identical to the saved one is not written and reports action "unchanged" (counted in unchanged). An item with errors writes nothing; the others go on. With "dryRun" nothing is written. body is plain HTML and is refused on items with a Nucleo document: edit their blocks with nucleo/doc-patch; create pages built with Nucleo blocks with nucleo/doc-create.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['items'],
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => self::MAX_ITEMS,
                        'description' => 'Items to create or update. Partial: a key left out keeps its value.',
                        'items' => [
                            'type' => 'object',
                            'required' => ['type', 'slug'],
                            'properties' => [
                                'type' => ['type' => 'string'],
                                'slug' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'status' => ['type' => 'string', 'description' => 'draft (default on create), publish, private, future, pending, or trash to trash the item. Invalid values are reported per item.'],
                                'date' => ['type' => 'string', 'description' => 'Local site time, Y-m-d\\TH:i:s or Y-m-d.'],
                                'excerpt' => ['type' => 'string'],
                                'body' => ['type' => 'string'],
                                'featuredImage' => ['type' => ['string', 'integer', 'null'], 'description' => 'media:<file>, attachment ID, or null.'],
                                'terms' => ['type' => 'object', 'description' => 'Per taxonomy, a list of term slugs.'],
                                'fields' => ['type' => 'object', 'description' => 'Field values, with symbolic references.'],
                                'parent' => ['type' => ['string', 'null'], 'description' => 'Slug of an item of the same type, or null.'],
                                'menuOrder' => ['type' => 'integer', 'description' => 'Order for content-list and nc/collection (orderBy menu_order). Any content type.'],
                                'base' => ['type' => 'string', 'description' => 'Hash read with content-list "full". If the item exists and has changed since, it fails with code conflict and nothing is written. Not stored.'],
                            ],
                        ],
                    ],
                    'dryRun' => ['type' => 'boolean', 'description' => 'Check the items and report the planned actions, without writing. Default false.'],
                    'media' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string'],
                        'description' => 'Map from file name to URL, from the manifest.',
                    ],
                    'pending' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Symbolic references that the push will create. Only with dryRun.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean', 'description' => 'True when there are no errors.'],
                    'created' => ['type' => 'integer'],
                    'updated' => ['type' => 'integer'],
                    'unchanged' => ['type' => 'integer', 'description' => 'Existing items identical to the input: not written.'],
                    'trashed' => ['type' => 'integer'],
                    'results' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'index' => ['type' => 'integer'],
                                'slug' => ['type' => 'string'],
                                'action' => ['type' => 'string', 'enum' => ['created', 'updated', 'unchanged', 'trashed']],
                                'id' => ['type' => ['integer', 'null']],
                                'hash' => ['type' => ['string', 'null'], 'description' => 'Fingerprint of the saved item, to use as "base" next time. Absent with dryRun.'],
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
                                'type' => ['type' => 'string'],
                                'path' => ['type' => 'string', 'description' => 'JSON Pointer inside the item.'],
                                'code' => ['type' => 'string', 'description' => 'For example "conflict": the item changed after "base" was read (path /base).'],
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
                'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function execute(array $input): array
    {
        $items = is_array($input['items'] ?? null) ? array_values($input['items']) : [];
        if ($items === [] || count($items) > self::MAX_ITEMS) {
            return ['ok' => false, 'error' => ['code' => 'invalid_input', 'message' => __('Give from 1 to 100 items.', 'nucleo')]];
        }

        $media = is_array($input['media'] ?? null) ? $input['media'] : [];
        $pending = is_array($input['pending'] ?? null) ? array_values($input['pending']) : [];

        $writer = new ContentWriter(
            dryRun: ($input['dryRun'] ?? false) === true,
            media: $media,
            pending: $pending,
            canPublish: static fn (): bool => self::canPublish(),
        );
        $result = $writer->upsert($items);

        return [
            'ok' => $result['errors'] === [],
            'created' => $result['created'],
            'updated' => $result['updated'],
            'unchanged' => $result['unchanged'],
            'trashed' => $result['trashed'],
            'results' => $result['results'],
            'errors' => $result['errors'],
        ];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_posts');
    }

    /** Senza token OAuth vale il permesso WordPress, che il writer controlla per conto suo; con token serve nucleo:publish. */
    private static function canPublish(): bool
    {
        $scopes = CurrentToken::scopes();

        return $scopes === null || in_array(Scopes::PUBLISH, $scopes, true);
    }
}
