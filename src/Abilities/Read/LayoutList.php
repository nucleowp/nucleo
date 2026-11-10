<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Services;

/** nucleo/layout-list: layout Nucleo con area, condizioni e stato, e conflitti della mappa. */
final class LayoutList implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/layout-list';
    }

    public function args(): array
    {
        return [
            'label' => __('Layout list', 'nucleo'),
            'description' => 'Nucleo layouts with area, conditions and status, plus priority conflicts in the map. To see which layout a URL gets use nucleo/layout-explain. nucleo/doc-list lists all Nucleo documents without conditions.',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false],
            'output_schema' => [
                'type' => 'object',
                'required' => ['layouts', 'conflicts'],
                'properties' => [
                    'layouts' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'title', 'area', 'conditions', 'status'],
                            'properties' => [
                                'id' => ['type' => 'integer', 'minimum' => 1],
                                'title' => ['type' => 'string'],
                                'area' => ['type' => 'string'],
                                'conditions' => ['type' => 'object'],
                                'status' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'conflicts' => ['type' => 'array'],
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
        $layouts = [];
        foreach (
            get_posts([
            'post_type' => 'nucleo_layout',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            ]) as $post
        ) {
            $raw = get_post_meta($post->ID, '_nucleo_doc', true);
            $doc = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $meta = is_array($doc) && is_array($doc['meta'] ?? null) ? $doc['meta'] : [];
            $layouts[] = [
                'id' => (int) $post->ID,
                'title' => $post->post_title,
                'area' => is_string($meta['area'] ?? null) ? $meta['area'] : '',
                'conditions' => is_array($meta['conditions'] ?? null) ? $meta['conditions'] : [],
                'status' => $post->post_status,
            ];
        }

        $artifact = Services::store()->get('layouts');
        $conflicts = is_array($artifact['conflicts'] ?? null) ? $artifact['conflicts'] : [];

        return ['layouts' => $layouts, 'conflicts' => $conflicts];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_theme_options');
    }
}
