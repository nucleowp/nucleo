<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

defined('ABSPATH') || exit;

use Nucleo\Quality\Budgets;
use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Compiler\DocumentService;
use Nucleo\Compiler\RebuildQueue;
use Nucleo\Services;
use Nucleo\Support\Conflicts;

/** nucleo/status: versione, ambiente e stato minimo di Nucleo. */
final class Status implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/status';
    }

    public function args(): array
    {
        return [
            'label' => __('Nucleo status', 'nucleo'),
            'description' => 'Nucleo, PHP and WordPress version, artifact store driver, number of documents, artifacts (count and total bytes of the current artifact folder), queue (pending rebuilds), failed (rebuilds that failed, with attempts and error), stale (ids of documents with a stale section or quality issues, max 50) and lastBuild (time of the newest artifact, null when there are none). After a plugin update every document is queued once, so queue counts all of them until the rebuild ends; a document that cannot be rebuilt (invalid, or blocking quality issues on a published post) keeps its previous HTML and appears in stale or failed.',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok', 'nucleoVersion', 'php', 'wordpress', 'store', 'documentCount', 'conflicts', 'artifacts', 'queue', 'failed', 'stale', 'lastBuild', 'budgets'],
                'properties' => [
                    'ok' => ['type' => 'boolean', 'const' => true],
                    'nucleoVersion' => ['type' => 'string'],
                    'php' => ['type' => 'string'],
                    'wordpress' => ['type' => 'string'],
                    'store' => ['type' => 'string', 'description' => 'Name of the class of the active store.'],
                    'documentCount' => ['type' => 'integer', 'minimum' => 0],
                    'artifacts' => [
                        'type' => 'object',
                        'required' => ['count', 'bytes'],
                        'properties' => [
                            'count' => ['type' => 'integer', 'minimum' => 0],
                            'bytes' => ['type' => 'integer', 'minimum' => 0],
                        ],
                    ],
                    'budgets' => [
                        'type' => 'object',
                        'description' => 'Performance budgets of the quality rules: css and js in bytes served by Nucleo, dom in estimated nodes. Filters nucleo/quality/{css,js,dom}_budget.',
                        'required' => ['css', 'js', 'dom'],
                        'properties' => [
                            'css' => ['type' => 'integer', 'minimum' => 0],
                            'js' => ['type' => 'integer', 'minimum' => 0],
                            'dom' => ['type' => 'integer', 'minimum' => 0],
                        ],
                    ],
                    'queue' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Documents waiting for a background rebuild.'],
                    'failed' => [
                        'type' => 'array',
                        'description' => 'Failed background rebuilds with attempt count and last error. After 3 attempts the id leaves the queue.',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'attempts', 'code', 'message'],
                            'properties' => [
                                'id' => ['type' => 'integer', 'minimum' => 0],
                                'attempts' => ['type' => 'integer', 'minimum' => 0],
                                'code' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'stale' => [
                        'type' => 'array',
                        'description' => 'Ids of documents marked stale after a section change (max 50).',
                        'items' => ['type' => 'integer', 'minimum' => 0],
                    ],
                    'lastBuild' => ['type' => ['string', 'null'], 'description' => 'Modification time (ISO 8601) of the newest artifact file, null when the folder is empty.'],
                    'conflicts' => [
                        'type' => 'array',
                        'description' => 'Active cache plugins that cancel the CSS variants for Nucleo templates.',
                        'items' => [
                            'type' => 'object',
                            'required' => ['plugin', 'issue', 'fix'],
                            'properties' => [
                                'plugin' => ['type' => 'string'],
                                'issue' => ['type' => 'string'],
                                'fix' => ['type' => 'string'],
                            ],
                        ],
                    ],
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
        $documents = new \WP_Query([
            'post_type' => 'any',
            'post_status' => 'any',
            'meta_key' => '_nucleo_doc',
            'fields' => 'ids',
            'posts_per_page' => 1,
        ]);
        $folder = self::folderStats(\Nucleo\Storage\ArtifactStoreFactory::base());

        return [
            'ok' => true,
            'nucleoVersion' => NUCLEO_VERSION,
            'php' => PHP_VERSION,
            'wordpress' => (string) get_bloginfo('version'),
            'store' => (new \ReflectionClass(Services::store()))->getShortName(),
            'documentCount' => (int) $documents->found_posts,
            'artifacts' => ['count' => $folder['count'], 'bytes' => $folder['bytes']],
            'queue' => RebuildQueue::pending(),
            'failed' => RebuildQueue::failed(),
            'stale' => self::staleIds(),
            'budgets' => Budgets::all(),
            'lastBuild' => $folder['lastModified'] > 0 ? gmdate('c', $folder['lastModified']) : null,
            'conflicts' => Conflicts::detect(),
        ];
    }

    /**
     * Documenti con una sezione o una qualità marcata come stale, al massimo 50.
     * @return list<int>
     */
    private static function staleIds(): array
    {
        $ids = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'posts_per_page' => 50,
            'no_found_rows' => true,
            'meta_query' => [
                'relation' => 'OR',
                ['key' => DocumentService::STALE_META, 'compare' => 'EXISTS'],
                ['key' => DocumentService::QUALITY_STALE_META, 'compare' => 'EXISTS'],
            ],
        ]);

        return array_values(array_map('intval', $ids));
    }

    /**
     * Numero di file, byte totali e data di modifica più recente di una cartella (ricorsiva); tutti zero se non esiste.
     * @return array{count:int,bytes:int,lastModified:int}
     */
    public static function folderStats(string $dir): array
    {
        $stats = ['count' => 0, 'bytes' => 0, 'lastModified' => 0];
        if (!is_dir($dir)) {
            return $stats;
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $stats['count']++;
            $stats['bytes'] += (int) $file->getSize();
            $stats['lastModified'] = max($stats['lastModified'], (int) $file->getMTime());
        }

        return $stats;
    }

    public function permitted(array $input): bool
    {
        return current_user_can('manage_options');
    }
}
