<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Services;

/** nucleo/doc-revisions: elenco delle revisioni di un documento, con client, riepilogo delle modifiche e, su richiesta, il contenuto. */
final class DocRevisions implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/doc-revisions';
    }

    public function args(): array
    {
        return [
            'label' => __('Document revisions', 'nucleo'),
            'description' => 'List of a document\'s revisions: number, time, author (WordPress user), client (agent id, "" for people) and summary of the nodes changed from the previous revision ("+added ~changed -removed"). The content of the revisions is included only with withDocs. To go back to a revision, use nucleo/doc-rollback.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['postId'],
                'properties' => [
                    'postId' => ['type' => 'integer', 'minimum' => 1],
                    'withDocs' => ['type' => 'boolean', 'default' => false, 'description' => 'When true each revision includes its document.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'postId' => ['type' => 'integer'],
                    'revisions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['rev', 'time', 'user', 'client', 'summary'],
                            'properties' => [
                                'rev' => ['type' => 'integer', 'minimum' => 1],
                                'time' => ['type' => 'integer'],
                                'user' => ['type' => 'integer', 'minimum' => 0],
                                'client' => ['type' => 'string', 'description' => 'Id of the agent that saved the revision, "" for people.'],
                                'summary' => ['type' => 'string', 'description' => '"created" for the first revision, otherwise "+a ~c -r": nodes added, changed and removed.'],
                                'doc' => ['type' => 'object', 'description' => 'Document of the revision, only with withDocs.'],
                            ],
                        ],
                    ],
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
        $withDocs = $input['withDocs'] ?? false;
        if (!is_int($postId) || !is_bool($withDocs)) {
            return new \WP_Error('invalid_input', __('The "postId" field must be an integer and "withDocs" a boolean.', 'nucleo'));
        }
        if (get_post($postId) === null) {
            /* translators: %d: ID of the post that was not found. */
            return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => sprintf(__('Post not found: %d.', 'nucleo'), $postId)]];
        }

        $revisions = [];
        $previous = null;
        foreach (Services::repository()->history($postId) as $entry) {
            $doc = is_array($entry['doc'] ?? null) ? $entry['doc'] : [];
            $revision = [
                'rev' => (int) ($entry['rev'] ?? 0),
                'time' => (int) ($entry['time'] ?? 0),
                'user' => (int) ($entry['user'] ?? 0),
                'client' => (string) ($entry['client'] ?? ''),
                'summary' => $previous === null ? 'created' : self::summary($previous, $doc),
            ];
            if ($withDocs) {
                $revision['doc'] = $doc;
            }
            $revisions[] = $revision;
            $previous = $doc;
        }

        return ['ok' => true, 'postId' => $postId, 'revisions' => $revisions];
    }

    public function permitted(array $input): bool
    {
        $postId = $input['postId'] ?? 0;

        return is_int($postId) && $postId > 0 && current_user_can('edit_post', $postId);
    }

    /**
     * Riepilogo "+a ~c -r": nodi aggiunti, cambiati (campi diversi dai figli) e rimossi, confrontando gli id con la versione precedente.
     * @param array<string,mixed> $previous
     * @param array<string,mixed> $doc
     */
    private static function summary(array $previous, array $doc): string
    {
        $before = self::nodes($previous);
        $after = self::nodes($doc);
        $added = count(array_diff_key($after, $before));
        $removed = count(array_diff_key($before, $after));
        $changed = 0;
        foreach ($after as $id => $node) {
            if (isset($before[$id]) && self::ownFields($before[$id]) !== self::ownFields($node)) {
                $changed++;
            }
        }

        return sprintf('+%d ~%d -%d', $added, $changed, $removed);
    }

    /**
     * Nodi del documento per id, in qualunque profondità.
     * @param array<string,mixed> $doc
     * @return array<string,array<string,mixed>>
     */
    private static function nodes(array $doc): array
    {
        $nodes = [];
        if (is_array($doc['root'] ?? null)) {
            self::collect($doc['root'], $nodes);
        }

        return $nodes;
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,array<string,mixed>> $nodes
     */
    private static function collect(array $node, array &$nodes): void
    {
        if (is_string($node['id'] ?? null)) {
            $nodes[$node['id']] = $node;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                self::collect($child, $nodes);
            }
        }
    }

    /**
     * Campi del nodo senza i figli: il confronto tra versioni non risale ai discendenti.
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private static function ownFields(array $node): array
    {
        unset($node['children']);

        return $node;
    }
}
