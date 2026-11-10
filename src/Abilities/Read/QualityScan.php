<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Admin\Menu;
use Nucleo\Quality\SiteScan;

/** nucleo/quality-scan: controllo trasversale del sito a lotti. Scrive solo il contatore del menu. */
final class QualityScan implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/quality-scan';
    }

    public function args(): array
    {
        return [
            'label' => __('Site quality check', 'nucleo'),
            'description' => 'Checks all published documents in batches: duplicate title and h1, orphan pages without internal links, breadcrumbs duplicated by the SEO plugin, sections repeated with different visibility. Returns the site issues, the SEO, accessibility and performance scores of the pages in the batch, the rules with their severity (rules), the number of pages with blocking errors (errorPages) and the next page (next). For the full report of one document use nucleo/quality-check.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'page' => ['type' => 'integer', 'minimum' => 1],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['site', 'pages', 'next', 'rules', 'errorPages'],
                'properties' => [
                    'site' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'pages' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'next' => ['type' => ['integer', 'null']],
                    'rules' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'severity'],
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'severity' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'errorPages' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    public function execute(array $input): array|\WP_Error
    {
        $page = $input['page'] ?? 1;
        if (!is_int($page) || $page < 1) {
            return new \WP_Error('invalid_input', __('page must be an integer greater than zero.', 'nucleo'));
        }

        $result = SiteScan::run($page);
        if ($page === 1) {
            set_transient(Menu::BADGE_TRANSIENT, $result['errorPages'], DAY_IN_SECONDS);
        }

        return $result;
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_posts');
    }
}
