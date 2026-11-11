<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Site\Menus;

/** nucleo/menu-list: elenco dei menu di navigazione con le voci nel formato di nucleo/menu-set. */
final class MenuList implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/menu-list';
    }

    public function args(): array
    {
        return [
            'label' => __('Menu list', 'nucleo'),
            'description' => 'Lists the navigation menus with their items. Items use the same format as nucleo/menu-set: {label, page | post | url | ref, children}; ref is "home", "archive:<post-type>", "<post-type>:<slug>" or "term:<taxonomy>/<slug>". Read only.',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false],
            'output_schema' => [
                'type' => 'object',
                'required' => ['menus'],
                'properties' => [
                    'menus' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'slug', 'name', 'items'],
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'slug' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'items' => ['type' => 'array', 'items' => ['type' => 'object']],
                            ],
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
        $menus = [];
        foreach (Menus::export() as $slug => $menu) {
            $term = get_term_by('slug', (string) $slug, 'nav_menu');
            $menus[] = [
                'id' => $term instanceof \WP_Term ? (int) $term->term_id : 0,
                'slug' => (string) $slug,
                'name' => $menu['name'],
                'items' => $menu['items'],
            ];
        }

        return ['menus' => $menus];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_posts');
    }
}
