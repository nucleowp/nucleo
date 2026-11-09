<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Site\Gaps;

/** nucleo/gap-list: lacune di Nucleo segnalate dagli agenti, dalla più recente. */
final class GapList implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/gap-list';
    }

    public function args(): array
    {
        return [
            'label' => __('Nucleo gaps', 'nucleo'),
            'description' => 'Things the design needed that Nucleo could not do, reported with gap-report: need, block, workaround, page, count (times reported), first and last date (Y-m-d). Newest first.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => new \stdClass(),
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok', 'gaps'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'gaps' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['need', 'block', 'workaround', 'page', 'count', 'first', 'last'],
                            'properties' => [
                                'need' => ['type' => 'string'],
                                'block' => ['type' => 'string'],
                                'workaround' => ['type' => 'string'],
                                'page' => ['type' => 'string'],
                                'count' => ['type' => 'integer', 'minimum' => 1],
                                'first' => ['type' => 'string'],
                                'last' => ['type' => 'string'],
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
        return ['ok' => true, 'gaps' => Gaps::all()];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_posts');
    }
}
