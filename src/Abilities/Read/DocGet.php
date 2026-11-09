<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Schema\CompactFormatter;
use Nucleo\Schema\PatchException;
use Nucleo\Services;

/** nucleo/doc-get: documento in forma compatta (predefinita) o intero, intero o per sottoalbero. */
final class DocGet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/doc-get';
    }

    public function args(): array
    {
        return [
            'label' => __('Read document', 'nucleo'),
            'description' => 'Nucleo document in compact form (default) or in full (format "full": the stored document unchanged), whole or only a subtree (nodeId), with a maximum depth (depth, compact only). Also returns the revision: pass it as baseRevision to doc-patch, which refuses with conflict if the document changed since.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['postId'],
                'properties' => [
                    'postId' => ['type' => 'integer', 'minimum' => 1],
                    'nodeId' => ['type' => 'string', 'minLength' => 1, 'description' => 'Id of the node where the subtree starts.'],
                    'depth' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Beyond this depth, children are replaced by childCount. Not used with format "full".'],
                    'format' => ['type' => 'string', 'enum' => ['compact', 'full'], 'default' => 'compact'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'postId' => ['type' => 'integer'],
                    'revision' => ['type' => 'integer', 'minimum' => 0],
                    'document' => ['type' => 'object'],
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
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    public function execute(array $input): array|\WP_Error
    {
        $postId = $input['postId'] ?? null;
        $nodeId = $input['nodeId'] ?? null;
        $depth = $input['depth'] ?? null;
        $format = $input['format'] ?? 'compact';
        if (
            !is_int($postId) || ($nodeId !== null && !is_string($nodeId)) || ($depth !== null && !is_int($depth))
            || !is_string($format) || !in_array($format, ['compact', 'full'], true)
        ) {
            return new \WP_Error('invalid_input', __('Invalid postId, nodeId, depth or format fields.', 'nucleo'));
        }

        $found = Services::documents()->get($postId);
        if ($found === null) {
            /* translators: %d: post ID of the requested document. */
            return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => sprintf(__('Document not found: %d.', 'nucleo'), $postId)]];
        }

        if ($format === 'full') {
            $document = $found['document'];
            if ($nodeId !== null) {
                $node = is_array($document['root'] ?? null) ? self::findNode($document['root'], $nodeId) : null;
                if ($node === null) {
                    /* translators: %s: node id */
                    return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => sprintf(__('node not found: %s', 'nucleo'), $nodeId)]];
                }
                $document = $node;
            }

            return ['ok' => true, 'postId' => $postId, 'revision' => $found['revision'], 'document' => $document];
        }

        try {
            $compact = (new CompactFormatter())->compact($found['document'], $nodeId, $depth, self::blockDefaults());
        } catch (PatchException $e) {
            return ['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
        }

        return ['ok' => true, 'postId' => $postId, 'revision' => $found['revision'], 'document' => $compact];
    }

    public function permitted(array $input): bool
    {
        $postId = $input['postId'] ?? 0;

        return is_int($postId) && $postId > 0 && current_user_can('edit_post', $postId);
    }

    /**
     * Nodo con l'id dato, in qualunque profondità, o null.
     * @param array<string,mixed> $node
     * @return array<string,mixed>|null
     */
    private static function findNode(array $node, string $nodeId): ?array
    {
        if (($node['id'] ?? null) === $nodeId) {
            return $node;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child) && ($found = self::findNode($child, $nodeId)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Valori di default delle impostazioni per blocco, nella forma attesa da CompactFormatter.
     * @return array<string,array{settings:array<string,mixed>}>
     */
    private static function blockDefaults(): array
    {
        $defaults = [];
        foreach (Services::registry()->all() as $type => $manifest) {
            $settings = [];
            foreach ($manifest->settings as $key => $schema) {
                if (is_array($schema) && array_key_exists('default', $schema)) {
                    $settings[$key] = $schema['default'];
                }
            }
            $defaults[$type] = ['settings' => $settings];
        }

        return $defaults;
    }
}
