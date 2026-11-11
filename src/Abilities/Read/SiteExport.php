<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Site\SiteExporter;

/** nucleo/site-export: il sito come file JSON (token, breakpoint, documenti, regole). */
final class SiteExport implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/site-export';
    }

    public function args(): array
    {
        return [
            'label' => __('Export site', 'nucleo'),
            'description' => 'Returns the site as a map of JSON files: site.json, pages, posts, layouts, sections, rules. References between documents are readable symbols (page:slug, section:slug).',
            'input_schema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['files'],
                'properties' => [
                    'files' => [
                        'type' => 'object',
                        'description' => 'Relative path → JSON content.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'skipped' => [
                        'type' => 'array',
                        'description' => 'Menu items left out of menus/*.json because the import would reject them: target not published, deleted or not public.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'menu' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'reason' => ['type' => 'string'],
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

    public function execute(array $input): array
    {
        $skipped = [];
        $files = SiteExporter::files($skipped);

        return ['files' => $files, 'skipped' => $skipped];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('manage_options');
    }
}
