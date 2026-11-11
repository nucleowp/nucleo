<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Content\ContentTypes;
use Nucleo\Content\ModelRepository;

/** nucleo/model-get: modello dei contenuti salvato e quanti contenuti, termini e valori lo usano. */
final class ModelGet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/model-get';
    }

    public function args(): array
    {
        return [
            'label' => __('Content model', 'nucleo'),
            'description' => 'Returns the saved content model (post types, taxonomies, field groups), how many items use each part, and "unmodeled": the post types and taxonomies of the site that the model does not declare, with registeredBy (wordpress, or plugin-or-theme: registered by another plugin or the theme, so model-set cannot declare that slug; it can add a field group to it). Model format: catalog-get {topic: model}.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok', 'model', 'usage', 'unmodeled'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'model' => ['type' => 'object'],
                    'usage' => ['type' => 'object'],
                    'unmodeled' => [
                        'type' => 'object',
                        'properties' => [
                            'postTypes' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'taxonomies' => ['type' => 'array', 'items' => ['type' => 'object']],
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
        $model = ModelRepository::model();

        return ['ok' => true, 'model' => $model, 'usage' => ModelRepository::usage($model), 'unmodeled' => self::unmodeled($model)];
    }

    /**
     * Tipi e tassonomie del sito che il modello non dichiara, esclusi post e page e le tassonomie di WordPress.
     *
     * @param array<string,mixed> $model
     * @return array{postTypes: list<array{slug:string,label:string,registeredBy:string}>, taxonomies: list<array{slug:string,label:string,registeredBy:string}>}
     */
    private static function unmodeled(array $model): array
    {
        $out = ['postTypes' => [], 'taxonomies' => []];
        foreach (ContentTypes::inventory() as $part => $entries) {
            $declared = array_column($model[$part], 'slug');
            foreach ($entries as $entry) {
                if ($entry['registeredBy'] !== ContentTypes::BUILTIN && !in_array($entry['slug'], $declared, true)) {
                    $out[$part][] = ['slug' => $entry['slug'], 'label' => $entry['label'], 'registeredBy' => $entry['registeredBy']];
                }
            }
        }

        return $out;
    }

    public function permitted(array $input): bool
    {
        return current_user_can('manage_options');
    }
}
