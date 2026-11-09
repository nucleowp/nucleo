<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Content\ModelDiff;
use Nucleo\Content\ModelError;
use Nucleo\Content\ModelRepository;

/** nucleo/model-validate: controlla un modello dei contenuti senza salvarlo e ne mostra le differenze. */
final class ModelValidate implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/model-validate';
    }

    public function args(): array
    {
        return [
            'label' => __('Validate content model', 'nucleo'),
            'description' => 'Checks a content model without saving it, with the same rules as model-set (postTypes, taxonomies or fieldGroups not sent keep their saved value). Returns the errors and the differences from the saved model. Nothing is written. Format: catalog-get {topic: model}.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['model'],
                'additionalProperties' => false,
                'properties' => [
                    'model' => ['type' => 'object', 'description' => 'Content model document.'],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'errors' => ['type' => 'array'],
                    'diff' => ['type' => ['object', 'null'], 'description' => 'Null when the schema itself is invalid.'],
                    'kept' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Parts not sent, kept as saved.'],
                    'error' => ['type' => 'object'],
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
        $model = $input['model'] ?? null;
        if (!is_array($model)) {
            return self::invalid(__('"model" must be an object.', 'nucleo'));
        }
        ['model' => $model, 'kept' => $kept] = ModelRepository::merge($model);

        $errors = array_map(static fn (ModelError $error): array => $error->toArray(), ModelRepository::explainKept(ModelRepository::validate($model), $kept));

        // Con errori di forma il modello non ha una struttura su cui calcolare il diff.
        $diff = null;
        if (!in_array('schema', array_column($errors, 'code'), true)) {
            $saved = ModelRepository::model();
            $diff = ModelDiff::between($saved, $model, ModelRepository::usage($saved));
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'diff' => $diff, 'kept' => $kept];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('manage_options');
    }

    /** @return array{ok:false,error:array{code:string,message:string}} */
    private static function invalid(string $message): array
    {
        return ['ok' => false, 'error' => ['code' => 'invalid_input', 'message' => $message]];
    }
}
