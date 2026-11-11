<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Content\ModelDiff;
use Nucleo\Content\ModelError;
use Nucleo\Content\ModelRegistrar;
use Nucleo\Content\ModelRepository;

/** nucleo/model-set: salva il modello dei contenuti. Valida e calcola il diff prima di scrivere. */
final class ModelSet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/model-set';
    }

    public function args(): array
    {
        return [
            'label' => __('Save content model', 'nucleo'),
            'description' => 'Saves the content model. Format: {"schema": 1, "postTypes": [{"slug", "singular", "plural", "public"?, "hasArchive"?, "rewrite"?, "supports"?, "icon"?}], "taxonomies": [{"slug", "singular", "plural", "postTypes"?, "hierarchical"?, "public"?}], "fieldGroups": [{"id", "title", "location": {"postType"} or {"taxonomy"}, "fields": [{"key", "type", "label", ...}]}]}. "singular" and "plural" are the labels. Partial save: postTypes, taxonomies and fieldGroups that you do not send stay as saved; a key you send replaces the whole list (send [] to empty it). Slugs: lowercase, post types max 20 characters, taxonomies max 32; reserved_slug = a WordPress slug (post, page, category, wp_...) or a type registered by another plugin or the theme (model-get "unmodeled" shows them: add a field group to them instead). Every rule is checked before anything is written. Destructive changes need their ids in "confirm". With "dryRun" nothing is written. Full rules and field types: catalog-get {topic: model}.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['model'],
                'additionalProperties' => false,
                'properties' => [
                    'model' => ['type' => 'object', 'description' => 'Content model document. Missing postTypes, taxonomies or fieldGroups keep their saved value.'],
                    'dryRun' => ['type' => 'boolean', 'description' => 'Return the diff without saving.'],
                    'confirm' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Ids of destructive changes to accept, for example "field:dettagli/prezzo".',
                    ],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'diff' => ['type' => 'object'],
                    'kept' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Parts not sent, kept as saved.'],
                    'errors' => ['type' => 'array'],
                    'error' => ['type' => 'object'],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
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

        $dryRun = $input['dryRun'] ?? false;
        if (!is_bool($dryRun)) {
            return self::invalid(__('"dryRun" must be a boolean.', 'nucleo'));
        }

        $confirm = $input['confirm'] ?? [];
        if (!is_array($confirm) || !array_is_list($confirm) || array_filter($confirm, static fn (mixed $id): bool => !is_string($id)) !== []) {
            return self::invalid(__('"confirm" must be a list of ids.', 'nucleo'));
        }

        $errors = ModelRepository::explainKept(ModelRepository::validate($model), $kept);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => array_map(static fn (ModelError $error): array => $error->toArray(), $errors)];
        }

        $saved = ModelRepository::model();
        $diff = ModelDiff::between($saved, $model, ModelRepository::usage($saved));

        $unconfirmed = array_values(array_filter(
            $diff['destructive'],
            static fn (array $change): bool => !in_array($change['id'], $confirm, true),
        ));
        if ($unconfirmed !== []) {
            return [
                'ok' => false,
                'error' => [
                    'code' => 'destructive',
                    'message' => sprintf(
                        /* translators: %s: comma-separated ids of destructive changes */
                        __('Destructive changes need confirmation: %s.', 'nucleo'),
                        implode(', ', array_column($unconfirmed, 'id')),
                    ),
                ],
                'diff' => $diff,
            ];
        }

        if ($dryRun) {
            return ['ok' => true, 'diff' => $diff, 'kept' => $kept];
        }

        ModelRepository::save($model);
        if (self::structureChanged($diff)) {
            ModelRegistrar::registerAll();
            flush_rewrite_rules();
        }

        return ['ok' => true, 'diff' => $diff, 'kept' => $kept];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Tipi o tassonomie aggiunti, tolti o cambiati: solo allora servono le regole di riscrittura.
     *
     * @param array{added: list<string>, removed: list<string>, changed: list<string>, destructive: list<array{id: string, reason: string}>} $diff
     */
    private static function structureChanged(array $diff): bool
    {
        foreach (['added', 'removed', 'changed'] as $bucket) {
            foreach ($diff[$bucket] as $id) {
                if (str_starts_with($id, 'postType:') || str_starts_with($id, 'taxonomy:')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{ok:false,error:array{code:string,message:string}} */
    private static function invalid(string $message): array
    {
        return ['ok' => false, 'error' => ['code' => 'invalid_input', 'message' => $message]];
    }
}
