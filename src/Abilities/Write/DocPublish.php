<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Compiler\SaveOptions;
use Nucleo\Services;
use Nucleo\Storage\AuditLog;

/** nucleo/doc-publish: pubblica la bozza, ricompilando il documento corrente. */
final class DocPublish implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/doc-publish';
    }

    public function args(): array
    {
        return [
            'label' => __('Publish document', 'nucleo'),
            'description' => 'Publishes the current document. Blocking quality issues require acknowledge.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['postId'],
                'properties' => [
                    'postId' => ['type' => 'integer', 'minimum' => 1],
                    'acknowledge' => [
                        'type' => 'array',
                        'description' => 'Acknowledged quality issues, with a reason.',
                        'items' => [
                            'type' => 'object',
                            'required' => ['rule', 'node', 'reason'],
                            'properties' => [
                                'rule' => ['type' => 'string'],
                                'node' => ['type' => 'string'],
                                'reason' => ['type' => 'string'],
                            ],
                        ],
                    ],
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
        $acknowledge = self::acknowledgements($input['acknowledge'] ?? null);
        if (!is_int($postId) || $postId < 1 || $acknowledge === null) {
            return new \WP_Error('invalid_input', __('Invalid postId or acknowledge fields.', 'nucleo'));
        }

        $current = Services::documents()->get($postId);
        if ($current === null) {
            AuditLog::record('doc-publish', $postId, false, 'not_found');

            /* translators: %d: ID of the document that does not exist. */
            return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => sprintf(__('Document not found: %d.', 'nucleo'), $postId)]];
        }

        $result = Services::documents()->save($postId, $current['document'], new SaveOptions(
            publish: true,
            acknowledge: $acknowledge,
            baseRevision: $current['revision'],
        ));
        $summary = $result->ok ? 'publish' : ($result->error['code'] ?? 'error');
        AuditLog::record('doc-publish', $postId, $result->ok, $summary);

        return $result->toArray();
    }

    public function permitted(array $input): bool
    {
        $postId = $input['postId'] ?? 0;

        return is_int($postId) && $postId > 0 && current_user_can('publish_post', $postId);
    }

    /** @return list<array{rule:string,node:string,reason:string}>|null null se la forma non è valida */
    private static function acknowledgements(mixed $raw): ?array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }

        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !is_string($item['rule'] ?? null) || !is_string($item['node'] ?? null) || !is_string($item['reason'] ?? null)) {
                return null;
            }
            $out[] = ['rule' => $item['rule'], 'node' => $item['node'], 'reason' => $item['reason']];
        }

        return $out;
    }
}
