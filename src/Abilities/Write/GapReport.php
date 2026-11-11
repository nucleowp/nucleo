<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Site\Gaps;

/** nucleo/gap-report: segnala una cosa che il design chiede e Nucleo non sa fare, con il workaround usato. */
final class GapReport implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/gap-report';
    }

    public function args(): array
    {
        return [
            'label' => __('Report a Nucleo gap', 'nucleo'),
            'description' => 'Call this whenever Nucleo cannot do what the design needs, before any workaround. need: what is missing (1-500 characters). block: the nc/... block concerned, if any. workaround: what you did instead (at most 1000 characters); the workaround goes in style.custom of the node, never in mu-plugins, theme code or wp_head. page: the page concerned (at most 200 characters). Repeating the same block and need increases the count.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['need'],
                'properties' => [
                    'need' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500, 'description' => 'What Nucleo cannot do.'],
                    'block' => ['type' => 'string', 'description' => 'Nucleo block concerned, such as nc/section-ref.'],
                    'workaround' => ['type' => 'string', 'maxLength' => 1000, 'description' => 'What was done instead, in style.custom of the node.'],
                    'page' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Page concerned.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'count' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Times this gap has been reported.'],
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
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $result = Gaps::report(
            is_string($input['need'] ?? null) ? $input['need'] : '',
            is_string($input['block'] ?? null) ? $input['block'] : '',
            is_string($input['workaround'] ?? null) ? $input['workaround'] : '',
            is_string($input['page'] ?? null) ? $input['page'] : '',
        );
        if (is_wp_error($result)) {
            return ['ok' => false, 'error' => ['code' => $result->get_error_code(), 'message' => $result->get_error_message()]];
        }

        return ['ok' => true, 'count' => $result['count']];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_pages');
    }
}
