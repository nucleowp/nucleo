<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Services;

/** nucleo/catalog-list: elenco dei blocchi con descrizione breve. */
final class CatalogList implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/catalog-list';
    }

    public function args(): array
    {
        return [
            'label' => __('Block list', 'nucleo'),
            'description' => 'List of Nucleo blocks with title, category and short description. The full manifest, with style keys, HTML and base CSS, is read with nucleo/catalog-get {name}. Rules that no manifest holds are read with nucleo/catalog-get {topic}: documents (node format, ids, section parameters), tokens, styles (with the style groups of every block), bindings, visibility, data, theme, site-files, acknowledge, components.',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok', 'blocks'],
                'properties' => [
                    'ok' => ['type' => 'boolean', 'const' => true],
                    'blocks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['name', 'version', 'title', 'category', 'description'],
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'version' => ['type' => 'integer'],
                                'title' => ['type' => 'string'],
                                'category' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
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
        $blocks = [];
        foreach (Services::registry()->all() as $manifest) {
            $blocks[] = [
                'name' => $manifest->name,
                'version' => $manifest->version,
                'title' => $manifest->title,
                'category' => $manifest->category,
                'description' => $manifest->description,
            ];
        }

        return ['ok' => true, 'blocks' => $blocks];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_posts');
    }
}
