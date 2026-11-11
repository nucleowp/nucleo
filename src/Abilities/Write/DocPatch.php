<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Compiler\SaveOptions;
use Nucleo\Services;
use Nucleo\Storage\AuditLog;

/** nucleo/doc-patch: applica operazioni a un documento, con controllo di revisione. */
final class DocPatch implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/doc-patch';
    }

    public function args(): array
    {
        return [
            'label' => __('Edit document', 'nucleo'),
            'description' => 'Applies patch operations to an existing document. baseRevision must match the current revision, otherwise it returns conflict. With dryRun true it does not save. It edits the blocks of a Nucleo document; to publish use nucleo/doc-publish; title, fields and terms of the post go through nucleo/content-upsert.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['postId', 'baseRevision', 'ops'],
                'properties' => [
                    'postId' => ['type' => 'integer', 'minimum' => 1],
                    'baseRevision' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Revision read before the change.'],
                    'ops' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Operations applied in order.'],
                    'publish' => ['type' => 'boolean', 'default' => false],
                    'dryRun' => ['type' => 'boolean', 'default' => false],
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
        $baseRevision = $input['baseRevision'] ?? null;
        $ops = $input['ops'] ?? null;
        $publish = $input['publish'] ?? false;
        $dryRun = $input['dryRun'] ?? false;
        $acknowledge = self::acknowledgements($input['acknowledge'] ?? null);
        if (
            !is_int($postId) || $postId < 1 || !is_int($baseRevision) || $baseRevision < 0
            || !is_array($ops) || !array_is_list($ops) || array_filter($ops, static fn ($op): bool => !is_array($op)) !== []
            || !is_bool($publish) || !is_bool($dryRun) || $acknowledge === null
        ) {
            return new \WP_Error('invalid_input', __('Invalid postId, baseRevision, ops, publish, dryRun or acknowledge fields.', 'nucleo'), self::inputProblem($input));
        }

        $result = Services::documents()->patch($postId, $ops, new SaveOptions(
            publish: $publish,
            dryRun: $dryRun,
            acknowledge: $acknowledge,
            baseRevision: $baseRevision,
        ));
        $summary = $result->ok ? ($dryRun ? 'dry-run' : ($publish ? 'patch-publish' : 'patch')) : ($result->error['code'] ?? 'error');
        AuditLog::record('doc-patch', $postId, $result->ok, $summary);

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

    /**
     * Primo campo non valido: percorso, valore ricevuto, valori ammessi e correzione in una frase.
     * @param array<string,mixed> $input
     * @return array{path:string,received:string,allowed:string,fix:string}
     */
    private static function inputProblem(array $input): array
    {
        $checks = [
            ['postId', is_int($input['postId'] ?? null) && $input['postId'] >= 1, 'integer >= 1', 'Pass the integer id of an existing document.'],
            ['baseRevision', is_int($input['baseRevision'] ?? null) && $input['baseRevision'] >= 0, 'integer >= 0', 'Pass the revision read with nucleo/doc-get.'],
            ['ops', is_array($input['ops'] ?? null) && array_is_list($input['ops']) && array_filter($input['ops'], static fn ($op): bool => !is_array($op)) === [], 'list of objects', 'Pass ops as a list of operation objects, applied in order.'],
            ['publish', is_bool($input['publish'] ?? false), 'boolean', 'Pass publish as true or false.'],
            ['dryRun', is_bool($input['dryRun'] ?? false), 'boolean', 'Pass dryRun as true or false.'],
            ['acknowledge', self::acknowledgements($input['acknowledge'] ?? null) !== null, 'list of objects with rule, node and reason strings', 'Pass acknowledge as a list of objects with rule, node and reason as strings.'],
        ];
        foreach ($checks as [$path, $valid, $allowed, $fix]) {
            if (!$valid) {
                return ['path' => $path, 'received' => get_debug_type($input[$path] ?? null), 'allowed' => $allowed, 'fix' => $fix];
            }
        }

        return ['path' => 'input', 'received' => 'unknown', 'allowed' => 'postId, baseRevision, ops, publish, dryRun, acknowledge', 'fix' => 'Send the fields listed in the schema of nucleo/doc-patch.'];
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
