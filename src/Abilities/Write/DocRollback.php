<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Compiler\SaveOptions;
use Nucleo\Services;
use Nucleo\Storage\AuditLog;

/** nucleo/doc-rollback: ripristina una revisione precedente come nuova revisione. */
final class DocRollback implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/doc-rollback';
    }

    public function args(): array
    {
        return [
            'label' => __('Restore revision', 'nucleo'),
            'description' => 'Restores a previous revision of the document. The restore creates a new revision: the history is not lost.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['postId', 'revision'],
                'properties' => [
                    'postId' => ['type' => 'integer', 'minimum' => 1],
                    'revision' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Number of the revision to restore (see nucleo/doc-revisions).'],
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
                    'quality' => ['type' => 'object'],
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
                'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
            ],
        ];
    }

    public function execute(array $input): array|\WP_Error
    {
        $postId = $input['postId'] ?? null;
        $revision = $input['revision'] ?? null;
        if (!is_int($postId) || $postId < 1 || !is_int($revision) || $revision < 1) {
            return new \WP_Error('invalid_input', __('Invalid postId or revision fields.', 'nucleo'));
        }

        $current = Services::documents()->get($postId);
        $doc = $current === null ? null : Services::repository()->restore($postId, $revision);
        if ($current === null || $doc === null) {
            AuditLog::record('doc-rollback', $postId, false, 'not_found');

            /* translators: 1: revision number, 2: post ID. */
            return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => sprintf(__('Revision %1$d not found for post %2$d.', 'nucleo'), $revision, $postId)]];
        }

        $result = Services::documents()->save($postId, $doc, new SaveOptions(baseRevision: $current['revision']));
        $summary = $result->ok ? "rollback-to-$revision" : ($result->error['code'] ?? 'error');
        AuditLog::record('doc-rollback', $postId, $result->ok, $summary);

        return $result->toArray();
    }

    public function permitted(array $input): bool
    {
        $postId = $input['postId'] ?? 0;

        return is_int($postId) && $postId > 0 && current_user_can('edit_post', $postId);
    }
}
