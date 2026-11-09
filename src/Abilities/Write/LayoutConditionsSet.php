<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Compiler\SaveOptions;
use Nucleo\Services;
use Nucleo\Storage\AuditLog;

/** nucleo/layout-conditions-set: modifica le condizioni di applicazione di un layout e ricompila la mappa. */
final class LayoutConditionsSet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/layout-conditions-set';
    }

    public function args(): array
    {
        return [
            'label' => __('Edit layout conditions', 'nucleo'),
            'description' => 'Replaces the include, exclude and priority conditions of a layout. The rest of the document does not change. Each condition is {"type", ...keys of the type}, e.g. {"type": "post_type", "value": "prodotto"} or {"type": "post", "value": 12}; unknown types or keys are refused with code layout_condition and the expected format. All types: nucleo/catalog-get topic layouts. baseRevision, if provided, must match the current revision, otherwise it returns conflict. On a published layout an OAuth token also needs the scope nucleo:publish, besides nucleo:design.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['postId', 'conditions'],
                'properties' => [
                    'postId' => ['type' => 'integer', 'minimum' => 1],
                    'conditions' => [
                        'type' => 'object',
                        'required' => ['include'],
                        'properties' => [
                            'include' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'exclude' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'priority' => ['type' => 'integer'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'baseRevision' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Revision read with nucleo/doc-get.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'postId' => ['type' => 'integer', 'minimum' => 0],
                    'revision' => ['type' => 'integer', 'minimum' => 0],
                    'errors' => ['type' => 'array'],
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
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    public function execute(array $input): array|\WP_Error
    {
        $postId = $input['postId'] ?? null;
        $conditions = $input['conditions'] ?? null;
        $baseRevision = $input['baseRevision'] ?? null;
        // Come nei documenti: senza esclusioni e con priorità 0 se mancano.
        if (is_array($conditions)) {
            $conditions += ['exclude' => [], 'priority' => 0];
        }
        if (
            !is_int($postId) || $postId < 1 || !is_array($conditions)
            || !is_array($conditions['include'] ?? null) || !array_is_list($conditions['include'])
            || !is_array($conditions['exclude'] ?? null) || !array_is_list($conditions['exclude'])
            || !is_int($conditions['priority'] ?? null)
            || ($baseRevision !== null && (!is_int($baseRevision) || $baseRevision < 0))
        ) {
            return new \WP_Error('invalid_input', __('Fields postId, conditions (include, exclude, priority) or baseRevision are not valid.', 'nucleo'));
        }

        $found = Services::documents()->get($postId);
        if ($found === null || !is_array($found['document'])) {
            return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => sprintf(/* translators: %d: post ID */ __('Document does not exist or has no Nucleo content: %d.', 'nucleo'), $postId)]];
        }
        $post = get_post($postId);
        if (!$post instanceof \WP_Post || $post->post_type !== 'nucleo_layout' || !in_array($found['document']['kind'] ?? null, ['layout', 'popup'], true)) {
            return ['ok' => false, 'error' => ['code' => 'not_layout', 'message' => sprintf(/* translators: %d: post ID */ __('Post %d is not a Nucleo layout.', 'nucleo'), $postId)]];
        }

        // Cambia solo meta.conditions: il resto del documento resta com'è.
        $doc = $found['document'];
        $doc['meta'] = is_array($doc['meta'] ?? null) ? $doc['meta'] : [];
        $doc['meta']['conditions'] = $conditions;

        // Il salvataggio ricompila la mappa dei layout; publish false lascia lo stato del post invariato.
        $result = Services::documents()->save($postId, $doc, new SaveOptions(
            baseRevision: $baseRevision,
        ));
        $summary = $result->ok ? 'ok' : ($result->error['code'] ?? 'error');
        AuditLog::record('layout-conditions-set', $postId, $result->ok, $summary);

        return $result->toArray();
    }

    public function permitted(array $input): bool
    {
        $postId = $input['postId'] ?? 0;

        return is_int($postId) && $postId >= 1 && current_user_can('edit_post', $postId);
    }
}
