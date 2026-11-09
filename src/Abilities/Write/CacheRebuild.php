<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Compiler\RebuildQueue;
use Nucleo\Services;
use Nucleo\Storage\AuditLog;
use Nucleo\Storage\StoreMaintenance;

/**
 * nucleo/cache-rebuild: ricompila i documenti senza nuove revisioni e ripubblica i token.
 * Al massimo PAGE documenti per chiamata; il resto va in coda (RebuildQueue).
 */
final class CacheRebuild implements AbilityDefinition
{
    private const PAGE = 20;
    private const LIMIT = 200;

    public function name(): string
    {
        return 'nucleo/cache-rebuild';
    }

    public function args(): array
    {
        return [
            'label' => __('Rebuild cache', 'nucleo'),
            'description' => 'Rebuilds the documents listed in postIds, or all documents. Up to 20 are rebuilt right away, the rest goes to the background queue. Creates no new revisions. Also republishes tokens, asset rules, layouts and the content model from the database: use it when site-describe reports an empty Nucleo cache. A document that cannot be rebuilt (invalid, or blocking quality issues on a published post) keeps its previous output: see nucleo/status stale and failed.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'postIds' => [
                        'type' => 'array',
                        'maxItems' => self::LIMIT,
                        'items' => ['type' => 'integer', 'minimum' => 1],
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'rebuilt' => ['type' => 'integer', 'minimum' => 0],
                    'queued' => ['type' => 'integer', 'minimum' => 0],
                    'republished' => ['type' => 'boolean', 'description' => 'Tokens, asset rules, layouts and content model republished; false when another rebuild holds the lock.'],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    public function execute(array $input): array|\WP_Error
    {
        $postIds = $input['postIds'] ?? null;
        if (
            $postIds !== null && (!is_array($postIds) || !array_is_list($postIds) || count($postIds) > self::LIMIT
                || array_filter($postIds, static fn ($id): bool => !is_int($id) || $id < 1) !== [])
        ) {
            /* translators: %d: maximum number of post IDs accepted. */
            return new \WP_Error('invalid_input', sprintf(__('Invalid postIds field: positive integers, at most %d.', 'nucleo'), self::LIMIT));
        }

        $ids = $postIds !== null ? array_values(array_unique($postIds)) : self::allIds();
        $now = array_slice($ids, 0, self::PAGE);
        $later = array_slice($ids, self::PAGE);

        $rebuilt = 0;
        foreach ($now as $id) {
            if (Services::documents()->recompile($id)->ok) {
                $rebuilt++;
            }
        }
        if ($later !== []) {
            RebuildQueue::enqueue($later);
        }
        // Token, regole degli asset, layout e modello dal database: la stessa ricostruzione di admin_init.
        $republished = StoreMaintenance::rebuild(false);
        AuditLog::record('cache-rebuild', 0, $rebuilt === count($now), "$rebuilt documents rebuilt, " . count($later) . ' queued');

        return ['ok' => true, 'rebuilt' => $rebuilt, 'queued' => count($later), 'republished' => $republished];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('manage_options');
    }

    /** @return list<int> */
    private static function allIds(): array
    {
        global $wpdb;

        return array_values(array_map('intval', $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_nucleo_doc'"
            . " WHERE p.post_type IN ('page', 'post', 'nucleo_layout', 'nucleo_section') ORDER BY p.ID ASC"
        )));
    }
}
