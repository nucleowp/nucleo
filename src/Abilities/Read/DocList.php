<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Compiler\DocumentService;
use Nucleo\Support\Optional;

/** nucleo/doc-list: post con documento Nucleo (meta _nucleo_doc). */
final class DocList implements AbilityDefinition
{
    private const PER_PAGE = 20;
    /** Stati predefiniti: tutti tranne il cestino. */
    private const STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];
    private const QUALITY = ['errors', 'warnings', 'clean'];

    public function name(): string
    {
        return 'nucleo/doc-list';
    }

    public function args(): array
    {
        return [
            'label' => __('Document list', 'nucleo'),
            'description' => 'List of Nucleo documents with id, title, status, revision, link and quality (last check). Filters: post type, text search, status, quality, page. Nucleo documents are the block trees of pages, posts, layouts and sections. Items without a Nucleo document, and title, fields and terms of any item, go through nucleo/content-list. Sections with their parameters: nucleo/section-list; layouts with conditions and conflicts: nucleo/layout-list.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'postType' => ['type' => 'string', 'minLength' => 1, 'description' => 'Post type, e.g. "page". Default: all.'],
                    'search' => ['type' => 'string', 'maxLength' => 200],
                    'status' => [
                        'anyOf' => [
                            ['type' => 'string', 'enum' => self::STATUSES],
                            ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string', 'enum' => self::STATUSES]],
                        ],
                        'description' => 'Post status, or a list of them. Default: all except trash.',
                    ],
                    'quality' => ['type' => 'string', 'enum' => self::QUALITY, 'description' => 'errors: with errors; warnings: with warnings and no errors; clean: checked with no issues.'],
                    'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok', 'items', 'page', 'total'],
                'properties' => [
                    'ok' => ['type' => 'boolean', 'const' => true],
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'title', 'status', 'revision', 'link', 'modified', 'author', 'lang', 'quality'],
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'title' => ['type' => 'string'],
                                'status' => ['type' => 'string'],
                                'revision' => ['type' => 'integer', 'minimum' => 0],
                                'link' => ['type' => 'string'],
                                'modified' => ['type' => 'string', 'description' => 'ISO 8601 UTC, e.g. 2026-10-30T10:00:00Z.'],
                                'author' => ['type' => 'string', 'description' => 'Display name of the author, "" if none.'],
                                'lang' => ['type' => 'string', 'description' => 'Language slug of the post, "" without a multilingual plugin.'],
                                'quality' => [
                                    'type' => ['object', 'null'],
                                    'description' => 'Result of the last quality check saved with the document, null if never checked.',
                                    'properties' => [
                                        'errors' => ['type' => 'integer', 'minimum' => 0],
                                        'warnings' => ['type' => 'integer', 'minimum' => 0],
                                        'score' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'page' => ['type' => 'integer', 'minimum' => 1],
                    'total' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    public function execute(array $input): array|\WP_Error
    {
        $postType = $input['postType'] ?? 'any';
        $search = $input['search'] ?? '';
        $page = $input['page'] ?? 1;
        $status = $input['status'] ?? self::STATUSES;
        $quality = $input['quality'] ?? null;
        if (is_string($status)) {
            $status = [$status];
        }
        if (
            !is_string($postType) || !is_string($search) || !is_int($page) || $page < 1
            || !self::validStatus($status) || ($quality !== null && !in_array($quality, self::QUALITY, true))
        ) {
            return new \WP_Error('invalid_input', __('Invalid postType, search, page, status or quality fields.', 'nucleo'));
        }
        if ($postType !== 'any' && !post_type_exists($postType)) {
            /* translators: %s: the post type that was requested. */
            return ['ok' => false, 'error' => ['code' => 'invalid_post_type', 'message' => sprintf(__('Unknown post type: %s.', 'nucleo'), $postType)]];
        }

        $base = [
            'post_type' => $postType,
            // Senza filtro vale 'any' (tutto tranne cestino e bozze automatiche), come prima del filtro stato.
            'post_status' => isset($input['status']) ? $status : 'any',
            'perm' => 'editable',
            'meta_key' => '_nucleo_doc',
            's' => $search,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];
        if ($quality === null) {
            $query = new \WP_Query($base + ['posts_per_page' => self::PER_PAGE, 'paged' => $page]);
            $posts = is_array($query->posts) ? $query->posts : [];
            $total = (int) $query->found_posts;
        } else {
            // La qualità sta in un array nel meta: il filtro si fa su tutti i candidati, poi si pagina.
            $all = new \WP_Query($base + ['fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true]);
            $ids = [];
            foreach (is_array($all->posts) ? $all->posts : [] as $id) {
                if (is_int($id)) {
                    $ids[] = $id;
                }
            }
            $matching = array_values(array_filter($ids, static fn (int $id): bool => self::qualityClass(self::quality($id)) === $quality));
            $total = count($matching);
            $pageIds = array_slice($matching, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
            $posts = $pageIds === [] ? [] : get_posts([
                'post_type' => $postType,
                'post_status' => $status,
                'include' => $pageIds,
                'orderby' => 'post__in',
                'posts_per_page' => count($pageIds),
            ]);
        }

        $items = [];
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }
            $author = get_userdata((int) $post->post_author);
            $items[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'status' => $post->post_status,
                'revision' => (int) get_post_meta($post->ID, '_nucleo_rev', true),
                'link' => (string) get_permalink($post),
                // Le bozze hanno post_modified_gmt zero: si ricava dall'ora locale.
                'modified' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime(($post->post_modified_gmt !== '0000-00-00 00:00:00' ? $post->post_modified_gmt : get_gmt_from_date($post->post_modified)) . ' UTC')),
                'author' => $author instanceof \WP_User ? $author->display_name : '',
                'lang' => self::language($post->ID),
                'quality' => self::quality($post->ID),
            ];
        }

        return ['ok' => true, 'items' => $items, 'page' => $page, 'total' => $total];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_posts');
    }

    /** Lista non vuota di stati tra quelli ammessi. */
    private static function validStatus(mixed $status): bool
    {
        if (!is_array($status) || $status === [] || !array_is_list($status)) {
            return false;
        }
        foreach ($status as $value) {
            if (!is_string($value) || !in_array($value, self::STATUSES, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Esito dell'ultimo controllo di qualità salvato nel post, o null se il post non ne ha uno.
     * @return array{errors:int,warnings:int,score:mixed}|null
     */
    private static function quality(int $id): ?array
    {
        $meta = get_post_meta($id, DocumentService::QUALITY_META, true);
        if (!is_array($meta)) {
            return null;
        }

        return [
            'errors' => (int) ($meta['errors'] ?? 0),
            'warnings' => (int) ($meta['warnings'] ?? 0),
            'score' => $meta['score'] ?? null,
        ];
    }

    /**
     * Classe di qualità: errori prima degli avvisi; null se il post non ha un controllo.
     * @param array{errors:int,warnings:int,score:mixed}|null $quality
     */
    private static function qualityClass(?array $quality): ?string
    {
        if ($quality === null) {
            return null;
        }
        if ($quality['errors'] > 0) {
            return 'errors';
        }
        if ($quality['warnings'] > 0) {
            return 'warnings';
        }

        return 'clean';
    }

    /** Lingua del post dal plugin multilingua, '' senza plugin: Multilingual è Pro, quindi passa da Optional. */
    private static function language(int $id): string
    {
        $lang = Optional::call('Nucleo\\Integrations\\Multilingual', 'postLanguage', $id);

        return is_string($lang) ? $lang : '';
    }
}
