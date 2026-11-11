<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Abilities\Read\DocTexts;
use Nucleo\Compiler\SaveOptions;
use Nucleo\Services;
use Nucleo\Storage\AuditLog;

/** nucleo/doc-texts-set: riscrive i testi traducibili di un documento senza toccarne la struttura. */
final class DocTextsSet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/doc-texts-set';
    }

    public function args(): array
    {
        return [
            'label' => __('Edit document texts', 'nucleo'),
            'description' => 'Rewrites in bulk the texts of a document, identified by node id and path received from nucleo/doc-texts. Other fields and the structure do not change. baseRevision must match the current revision, otherwise it returns conflict.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['postId', 'baseRevision', 'texts'],
                'properties' => [
                    'postId' => ['type' => 'integer', 'minimum' => 1],
                    'baseRevision' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Revision read with nucleo/doc-texts.'],
                    'texts' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'path', 'text'],
                            'properties' => [
                                'id' => ['type' => 'string', 'minLength' => 1],
                                'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Path received from nucleo/doc-texts.'],
                                'text' => ['type' => 'string'],
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                    'publish' => ['type' => 'boolean', 'default' => false],
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
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
        ];
    }

    public function execute(array $input): array|\WP_Error
    {
        $postId = $input['postId'] ?? null;
        $baseRevision = $input['baseRevision'] ?? null;
        $texts = $input['texts'] ?? null;
        $publish = $input['publish'] ?? false;
        if (
            !is_int($postId) || $postId < 1 || !is_int($baseRevision) || $baseRevision < 0
            || !is_array($texts) || !array_is_list($texts) || !is_bool($publish)
            || array_filter($texts, static fn ($t): bool => !is_array($t) || !is_string($t['id'] ?? null)
                || !is_string($t['path'] ?? null) || !is_string($t['text'] ?? null)) !== []
        ) {
            return new \WP_Error('invalid_input', __('Fields postId, baseRevision, texts or publish are not valid.', 'nucleo'));
        }

        $found = Services::documents()->get($postId);
        if ($found === null || !is_array($found['document'])) {
            return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => sprintf(/* translators: %d: post ID */ __('Document does not exist or has no Nucleo content: %d.', 'nucleo'), $postId)]];
        }

        // Solo i percorsi che nucleo/doc-texts espone come traducibili: il resto della struttura resta intatto.
        $allowed = [];
        foreach (DocTexts::collect($found['document']) as $field) {
            $allowed[$field['id']][$field['path']] = true;
        }

        $ops = [];
        foreach ($texts as $t) {
            if (!isset($allowed[$t['id']][$t['path']])) {
                return ['ok' => false, 'error' => [
                    'code' => 'invalid_path',
                    'message' => sprintf(/* translators: %1$s: node id, %2$s: field path */ __('Field not translatable or node not found: %1$s %2$s. Use id and paths from nucleo/doc-texts.', 'nucleo'), $t['id'], $t['path']),
                ]];
            }
            $ops[] = ['op' => 'set', 'id' => $t['id'], 'path' => $t['path'], 'value' => $t['text']];
        }

        $result = Services::documents()->patch($postId, $ops, new SaveOptions(
            publish: $publish,
            baseRevision: $baseRevision,
        ));
        $summary = $result->ok ? ($publish ? 'texts-publish' : 'texts') : ($result->error['code'] ?? 'error');
        AuditLog::record('doc-texts-set', $postId, $result->ok, $summary);

        return $result->toArray();
    }

    public function permitted(array $input): bool
    {
        $postId = $input['postId'] ?? 0;
        if (!is_int($postId) || $postId < 1 || !current_user_can('edit_post', $postId)) {
            return false;
        }

        return ($input['publish'] ?? false) !== true || current_user_can('publish_post', $postId);
    }
}
