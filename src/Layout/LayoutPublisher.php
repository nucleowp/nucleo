<?php

declare(strict_types=1);

namespace Nucleo\Layout;

use Nucleo\Compiler\Translated;

defined('ABSPATH') || exit;

use Nucleo\Services;

/**
 * Tiene aggiornati gli artefatti dei layout: layouts (mappa) e layout-<id> (HTML e runtime).
 * Ricompila la mappa quando cambia un layout pubblicato.
 */
final class LayoutPublisher
{
    private const POST_TYPE = 'nucleo_layout';
    private const META_DOC = '_nucleo_doc';
    private const META_RT = '_nucleo_rt';
    private const NOT_PUBLISHED = ['draft', 'pending', 'private', 'future', 'trash'];

    /** Agancia gli hook una sola volta. */
    public static function register(): void
    {
        // has_action, not a static flag: the hook table can be reset (tests) while statics survive.
        if (has_action('nucleo/document_saved', [self::class, 'onSaved']) !== false) {
            return;
        }
        add_action('nucleo/document_saved', [self::class, 'onSaved'], 10, 1);
        add_action('transition_post_status', [self::class, 'onTransition'], 10, 3);
        add_action('transition_post_status', [self::class, 'onPagePublished'], 10, 3);
        add_action('deleted_post', [self::class, 'onDeleted'], 10, 2);
        add_action('post_updated', [self::class, 'onPostUpdated'], 10, 3);
        add_action('created_term', [self::class, 'onTermChanged'], 10, 0);
        add_action('edited_term', [self::class, 'onTermChanged'], 10, 0);
        add_action('delete_term', [self::class, 'onTermChanged'], 10, 0);
    }

    /** Termini creati, modificati o cancellati: cambiano i discendenti delle condizioni children. */
    public static function onTermChanged(): void
    {
        self::rebuild();
    }

    /**
     * Una pagina pubblicata figlia di una pagina citata da una condizione parent (che include i figli)
     * entra nella mappa solo con una ricompilazione.
     */
    public static function onPagePublished(string $newStatus, string $oldStatus, \WP_Post $post): void
    {
        if ($post->post_type !== 'page' || $newStatus !== 'publish' || $oldStatus === 'publish' || $post->post_parent < 1) {
            return;
        }
        if (self::parentIsReferenced((int) $post->post_parent)) {
            self::rebuild();
        }
    }

    /** Il genitore è citato da una condizione parent di un layout pubblicato, nell'inclusione o nell'esclusione. */
    private static function parentIsReferenced(int $parentId): bool
    {
        foreach (self::publishedIds() as $id) {
            $layout = self::describe($id);
            if ($layout === null) {
                continue;
            }
            foreach (array_merge($layout['include'], $layout['exclude']) as $condition) {
                if (($condition['type'] ?? null) === 'parent' && ($condition['value'] ?? null) === $parentId) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<int> */
    private static function publishedIds(): array
    {
        $ids = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        return array_values(array_map('intval', $ids));
    }

    /** Genitore di una pagina pubblicata cambiato: le condizioni parent della mappa vanno rigenerate. */
    public static function onPostUpdated(int $postId, \WP_Post $after, \WP_Post $before): void
    {
        if ($after->post_status !== 'publish' || $after->post_parent === $before->post_parent || !is_post_type_hierarchical($after->post_type)) {
            return;
        }
        self::rebuild();
    }

    /** Cancellazione definitiva senza cestino: nessun cambio di stato, la mappa va ricostruita qui. */
    public static function onDeleted(int $postId, ?\WP_Post $post = null): void
    {
        if ($post !== null && $post->post_type === self::POST_TYPE) {
            self::rebuild();
        }
    }

    public static function onSaved(int $postId): void
    {
        if (get_post_type($postId) !== self::POST_TYPE || get_post_status($postId) !== 'publish') {
            return;
        }

        self::putLayout($postId);
        self::rebuild();
    }

    private static function putLayout(int $postId): void
    {
        $rt = get_post_meta($postId, self::META_RT, true);
        $meta = self::docMeta($postId);
        Services::store()->put('layout-' . $postId, [
            'html' => (string) get_post_field('post_content', $postId),
            'rt' => is_array($rt) ? $rt : [],
            // Popup settings travel with the artifact: the runtime reads no post meta.
            'popup' => array_intersect_key($meta, ['trigger' => true, 'frequency' => true]) + ['title' => get_the_title($postId)],
        ]);
    }

    /** Riscrive l'artefatto di ogni layout pubblicato e la mappa, dal database (StoreMaintenance::rebuild). */
    public static function republish(): void
    {
        $ids = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);
        foreach ($ids as $id) {
            self::putLayout((int) $id);
        }
        self::rebuild();
    }

    /** Un layout che smette di essere pubblicato esce dalla mappa. */
    public static function onTransition(string $newStatus, string $oldStatus, \WP_Post $post): void
    {
        if ($post->post_type !== self::POST_TYPE || $oldStatus !== 'publish' || $newStatus === 'publish') {
            return;
        }
        self::rebuild();
    }

    /** Ricompila la mappa dai layout pubblicati e cancella gli artefatti dei layout non pubblicati. */
    public static function rebuild(): void
    {
        $store = Services::store();

        $layouts = [];
        $translations = [];
        foreach (self::publishedIds() as $id) {
            // Una traduzione segue le condizioni del suo originale: resta fuori dalla mappa.
            $source = Translated::source($id);
            if ($source !== null) {
                $translations[$source['id']][$source['lang']] = $id;
                continue;
            }
            $layout = self::describe($id);
            if ($layout !== null) {
                $layouts[] = $layout;
            }
        }

        $compiled = (new LayoutMapCompiler())->compile($layouts, self::descendants(...));
        $store->put('layouts', $compiled + ['translations' => $translations]);

        $unpublished = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => self::NOT_PUBLISHED,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);
        foreach ($unpublished as $id) {
            $store->delete('layout-' . (int) $id);
        }
    }

    /**
     * @return array{id:int,area:string,priority:int,include:list<array<string,mixed>>,exclude:list<array<string,mixed>>}|null
     */
    private static function describe(int $id): ?array
    {
        $meta = self::docMeta($id);

        $area = $meta['area'] ?? null;
        if (!is_string($area) || $area === '') {
            return null;
        }
        $conditions = is_array($meta['conditions'] ?? null) ? $meta['conditions'] : [];
        $priority = $conditions['priority'] ?? 0;

        return [
            'id' => $id,
            'area' => $area,
            'priority' => is_int($priority) ? $priority : 0,
            'include' => self::conditionList($conditions['include'] ?? null),
            'exclude' => self::conditionList($conditions['exclude'] ?? null),
        ];
    }

    /**
     * Meta del documento (area, conditions): _nucleo_doc è JSON salvato con wp_slash.
     *
     * @return array<string,mixed>
     */
    private static function docMeta(int $postId): array
    {
        $raw = get_post_meta($postId, self::META_DOC, true);
        $doc = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $meta = is_array($doc) ? ($doc['meta'] ?? null) : null;

        return is_array($meta) ? $meta : [];
    }

    /** @return list<array<string,mixed>> */
    private static function conditionList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $condition) {
            if (is_array($condition)) {
                $out[] = $condition;
            }
        }

        return $out;
    }

    /** @return list<int> */
    private static function descendants(string $kind, string $group, int $id): array
    {
        if ($kind === 'post') {
            $pages = get_pages(['child_of' => $id, 'post_type' => $group]);
            if ($pages === false) {
                return [];
            }

            return array_values(array_map(static fn (\WP_Post $page): int => (int) $page->ID, $pages));
        }

        $children = get_term_children($id, $group);
        if (is_wp_error($children)) {
            return [];
        }

        return array_values(array_map('intval', $children));
    }
}
