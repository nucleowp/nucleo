<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Layout\PostTypes;
use Nucleo\Services;

/** nucleo/section-list: sezioni riutilizzabili con parametri e numero di documenti che le usano. */
final class SectionList implements AbilityDefinition
{
    private const LIMIT = 200;

    /** Stessa meta di Compiler\Invalidator::DEP_META (privata lì): le dipendenze dei documenti. */
    private const DEP_META = '_nucleo_dep';

    public function name(): string
    {
        return 'nucleo/section-list';
    }

    public function args(): array
    {
        return [
            'label' => __('Section list', 'nucleo'),
            'description' => 'Reusable sections with their parameters and the number of documents that use them. Check here before creating a section. nucleo/doc-list lists all Nucleo documents without parameters; read the blocks of a section with nucleo/doc-get.',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok', 'sections'],
                'properties' => [
                    'ok' => ['type' => 'boolean', 'const' => true],
                    'sections' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'slug', 'title', 'status', 'params', 'uses', 'modified'],
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'slug' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'status' => ['type' => 'string'],
                                'params' => ['type' => 'object'],
                                'uses' => ['type' => 'integer', 'minimum' => 0],
                                'modified' => ['type' => 'string', 'description' => 'ISO 8601 UTC, e.g. 2026-10-30T10:00:00Z.'],
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
        // Chi gestisce il tema vede tutte le sezioni non cestinate ('any' esclude il cestino);
        // gli altri vedono solo quelle pubblicate.
        $post_status = current_user_can('edit_theme_options') ? 'any' : 'publish';

        $query = new \WP_Query([
            'post_type' => PostTypes::SECTION,
            'post_status' => $post_status,
            'orderby' => 'title',
            'order' => 'ASC',
            'posts_per_page' => self::LIMIT,
            'no_found_rows' => true,
        ]);

        $dependents = $this->dependentsBySection();

        $sections = [];
        $posts = is_array($query->posts) ? $query->posts : [];
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $found = Services::repository()->find($post->ID);
            $params = $found['document']['params'] ?? [];
            if (!is_array($params)) {
                $params = [];
            }

            // Unione per chiave (ID del post): ogni documento conta una sola volta.
            $users = ($dependents['id'][$post->ID] ?? []) + ($dependents['slug'][$post->post_name] ?? []);

            $sections[] = [
                'id' => $post->ID,
                'slug' => $post->post_name,
                'title' => $post->post_title,
                'status' => $post->post_status,
                // Oggetto vuoto in JSON, non array vuoto.
                'params' => $params === [] ? new \stdClass() : $params,
                'uses' => count($users),
                // Le bozze hanno post_modified_gmt zero: si ricava dall'ora locale.
                'modified' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime(($post->post_modified_gmt !== '0000-00-00 00:00:00' ? $post->post_modified_gmt : get_gmt_from_date($post->post_modified)) . ' UTC')),
            ];
        }

        return ['ok' => true, 'sections' => $sections];
    }

    /**
     * Una sola query per tutte le sezioni: per ID e per slug, insiemi di post_id che le usano.
     *
     * @return array{id: array<int|string, array<int, true>>, slug: array<int|string, array<int, true>>}
     */
    private function dependentsBySection(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND (meta_value LIKE %s OR meta_value LIKE %s)",
            self::DEP_META,
            $wpdb->esc_like('section:') . '%',
            $wpdb->esc_like('section-slug:') . '%',
        ), ARRAY_A);

        $byId = [];
        $bySlug = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $postId = (int) $row['post_id'];
            $value = (string) $row['meta_value'];
            if (str_starts_with($value, 'section-slug:')) {
                $bySlug[substr($value, strlen('section-slug:'))][$postId] = true;
            } elseif (str_starts_with($value, 'section:')) {
                $byId[substr($value, strlen('section:'))][$postId] = true;
            }
        }

        return ['id' => $byId, 'slug' => $bySlug];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_posts');
    }
}
