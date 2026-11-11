<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Services;

/** nucleo/tokens-get: token di design attuali. */
final class TokensGet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/tokens-get';
    }

    public function args(): array
    {
        return [
            'label' => __('Design tokens', 'nucleo'),
            'description' => 'Current design tokens of the site (colors, typography, spacing, etc.), grouped by category.',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok', 'tokens'],
                'properties' => [
                    'ok' => ['type' => 'boolean', 'const' => true],
                    'tokens' => ['type' => 'object'],
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
        return ['ok' => true, 'tokens' => Services::tokens()->toArray()];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_posts');
    }
}
