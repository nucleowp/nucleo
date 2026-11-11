<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Guide\GuideRules;

/** nucleo/guide: fase della guida a fasi, con regole, cose da fare e condizioni di uscita. Sola lettura. */
final class Guide implements AbilityDefinition
{
    private const DESIGN_MAX = 500;

    public function name(): string
    {
        return 'nucleo/guide';
    }

    public function args(): array
    {
        return [
            'label' => __('Guide', 'nucleo'),
            'description' => 'Phase of the site guide: state of the site, rules of the phase, open tasks and exit conditions. Entry point: call it before writing, then follow next. Each rule names the tools to use. To read a block use nucleo/catalog-get {name}, a rule topic nucleo/catalog-get {topic}; when the name is unknown use nucleo/docs-search; the index of blocks is nucleo/catalog-list.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'phase' => ['type' => 'integer', 'minimum' => 0, 'maximum' => GuideRules::LAST],
                    'design' => ['type' => 'string', 'maxLength' => self::DESIGN_MAX],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok', 'phase', 'title', 'state', 'basics', 'todo', 'rules', 'exit', 'next'],
                'properties' => [
                    'ok' => ['type' => 'boolean', 'const' => true],
                    'phase' => ['type' => 'integer', 'minimum' => 0, 'maximum' => GuideRules::LAST],
                    'title' => ['type' => 'string'],
                    'state' => ['type' => 'object'],
                    'basics' => [
                        'type' => 'array',
                        'description' => 'Phase 0 rules in short form; empty when phase is 0.',
                        'items' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'text' => ['type' => 'string']]],
                    ],
                    'todo' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'rules' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['code', 'text', 'tools'],
                            'properties' => [
                                'code' => ['type' => 'string'],
                                'text' => ['type' => 'string'],
                                'tools' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                        ],
                    ],
                    'exit' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'next' => ['type' => ['string', 'null']],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    /** @return array<string,mixed>|\WP_Error */
    public function execute(array $input): array|\WP_Error
    {
        $phase = isset($input['phase']) ? (int) $input['phase'] : null;
        if ($phase !== null && ($phase < 0 || $phase > GuideRules::LAST)) {
            return new \WP_Error('nucleo_guide_phase', __('The phase must be between 0 and 4.', 'nucleo'));
        }
        $design = isset($input['design']) ? (string) $input['design'] : '';

        return \Nucleo\Guide\Guide::run($phase, $design);
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_posts');
    }
}
