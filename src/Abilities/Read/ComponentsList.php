<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Extend\Components;

/** nucleo/components-list: sorgenti dati e azioni dei plugin del sito, solo nomi e schemi. */
final class ComponentsList implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/components-list';
    }

    public function args(): array
    {
        return [
            'label' => __('Site components', 'nucleo'),
            'description' => 'Lists the data sources and actions registered by site plugins (hook nucleo/register_components): names and schemas, never code. Use them in nc/collection (source "component") and nc/native-form (action "component"). Component blocks are registered with nucleo/register_blocks and appear in catalog-get. To write a component: nucleo component new data-source|action|block. These are not documents: Nucleo documents are listed with nucleo/doc-list, blocks with nucleo/catalog-list.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok', 'dataSources', 'actions'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'dataSources' => ['type' => 'array'],
                    'actions' => ['type' => 'array'],
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
        return ['ok' => true] + Components::instance()->describe();
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_pages');
    }
}
