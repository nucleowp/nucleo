<?php

declare(strict_types=1);

namespace Nucleo\Content;

use Nucleo\Services;
use Nucleo\Site\SiteExporter;
use WP_Post;
use WP_Post_Type;
use WP_Query;

/**
 * Lettura di elementi e tipi per le ability content-list, content-get e il tipo dei contenuti (docs/contracts/content-items.md).
 * Gli elementi con un documento Nucleo hanno managedBy e nessun body. Le pagine di sistema hanno protected.
 */
final class ContentReader
{
    /** Stati di list() quando il filtro manca: tutti tranne il cestino. */
    private const STATUSES = ['publish', 'draft', 'private', 'future', 'pending'];

    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    /**
     * Elemento completo: tutte le chiavi, parent solo per i tipi gerarchici.
     *
     * @return array<string,mixed>
     */
    public static function item(WP_Post $post): array
    {
        $managedBy = self::managedBy($post);
        $item = [
            'type' => $post->post_type,
            'slug' => $post->post_name,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'date' => self::date($post),
            'excerpt' => $post->post_excerpt,
        ];
        if ($managedBy === null) {
            $item['body'] = $post->post_content;
        }

        $thumbnail = (int) get_post_thumbnail_id($post->ID);
        $item['featuredImage'] = $thumbnail > 0 ? ContentRefs::attachmentRef($thumbnail) : null;
        $item['terms'] = self::terms($post);
        $item['fields'] = self::fields($post);

        if (self::isHierarchical($post->post_type)) {
            $item['parent'] = self::parentSlug($post);
        }
        $item['menuOrder'] = (int) $post->menu_order;
        if ($managedBy !== null) {
            $item['managedBy'] = $managedBy;
        }
        if (self::isProtected($post)) {
            $item['protected'] = true;
        }

        return $item;
    }

    /**
     * Impronta dell'elemento: sha1 della forma completa senza id, hash e base. Serve come base di content-upsert.
     */
    public static function hash(WP_Post $post): string
    {
        return self::digest(self::item($post));
    }

    /**
     * @param array<string,mixed> $item
     */
    public static function digest(array $item): string
    {
        unset($item['id'], $item['hash'], $item['base']);

        return sha1((string) wp_json_encode($item));
    }

    /**
     * Primo elemento con questo slug (qualunque stato tranne il cestino). Null se il tipo non è ammesso,
     * se non c'è, o se lo slug è ambiguo nel tipo.
     *
     * @return array<string,mixed>|null
     */
    public static function get(string $type, string $slug): ?array
    {
        if (!ContentTypes::allowsType($type) || in_array($slug, self::ambiguousSlugs($type), true)) {
            return null;
        }

        $ids = get_posts([
            'name' => $slug,
            'post_type' => $type,
            'post_status' => 'any',
            'orderby' => 'ID',
            'order' => 'ASC',
            'numberposts' => 1,
            'fields' => 'ids',
        ]);
        $post = $ids === [] ? null : get_post((int) $ids[0]);

        return $post instanceof WP_Post ? self::item($post) : null;
    }

    /**
     * Elenco paginato di ciò che l'utente può modificare. Filtri: type, status (stringa o elenco), term "<tax>/<slug>",
     * search, page, perPage (max 100), full. Ordine: menu_order, data decrescente, ID.
     * Gli elementi senza slug o con slug ambiguo non sono in items ma in skipped.
     *
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>, skipped:list<array{type:string,id:int,slug:string,code:string,message:string}>, total:int, page:int, pages:int}
     */
    public static function list(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($filters['perPage'] ?? self::DEFAULT_PER_PAGE)));
        $empty = ['items' => [], 'skipped' => [], 'total' => 0, 'page' => $page, 'pages' => 0];

        $types = self::listTypes($filters['type'] ?? null);
        if ($types === []) {
            return $empty;
        }

        $status = array_values(array_filter(
            (array) ($filters['status'] ?? self::STATUSES),
            static fn (mixed $value): bool => is_string($value) && $value !== 'trash',
        ));
        if ($status === []) {
            return $empty;
        }

        $taxQuery = [];
        $term = (string) ($filters['term'] ?? '');
        if ($term !== '') {
            $parsed = self::parseTerm($term);
            if ($parsed === null) {
                return $empty;
            }
            $taxQuery = [['taxonomy' => $parsed[0], 'field' => 'slug', 'terms' => $parsed[1]]];
        }

        $query = new WP_Query([
            'post_type' => $types,
            'post_status' => $status,
            'perm' => 'editable',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => ['menu_order' => 'ASC', 'date' => 'DESC', 'ID' => 'ASC'],
            's' => trim((string) ($filters['search'] ?? '')),
            'tax_query' => $taxQuery,
        ]);

        $posts = is_array($query->posts) ? array_values(array_filter($query->posts, static fn (mixed $p): bool => $p instanceof WP_Post)) : [];
        self::primeCaches($query, $posts);

        $full = ($filters['full'] ?? false) === true;
        $items = [];
        $skipped = [];
        $ambiguous = [];
        foreach ($posts as $post) {
            if (!current_user_can('edit_post', $post->ID)) {
                continue;
            }
            $ambiguous[$post->post_type] ??= self::ambiguousSlugs($post->post_type);
            $skip = self::skipReason($post, $ambiguous[$post->post_type]);
            if ($skip !== null) {
                $skipped[] = $skip;
                continue;
            }
            if ($full) {
                $item = self::item($post);
                $item['hash'] = self::digest($item);
                $items[] = $item;
            } else {
                $items[] = self::summary($post);
            }
        }

        return [
            'items' => $items,
            'skipped' => $skipped,
            'total' => (int) $query->found_posts,
            'page' => $page,
            'pages' => (int) $query->max_num_pages,
        ];
    }

    /**
     * Tipi ammessi: etichetta, gerarchia, modellato e tassonomie ammesse.
     *
     * @return list<array{type:string, label:string, hierarchical:bool, modeled:bool, registeredBy:string, taxonomies:list<string>}>
     */
    public static function types(): array
    {
        $out = [];
        foreach (ContentTypes::types() as $type) {
            $object = get_post_type_object($type);
            if (!$object instanceof WP_Post_Type) {
                continue;
            }
            $out[] = [
                'type' => $type,
                'label' => $object->labels->name,
                'hierarchical' => $object->hierarchical,
                'modeled' => ContentTypes::modeled('postType', $type),
                'registeredBy' => ContentTypes::origin('postType', $type),
                'taxonomies' => self::taxonomiesOf($type),
            ];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function summary(WP_Post $post): array
    {
        $summary = [
            'id' => $post->ID,
            'type' => $post->post_type,
            'slug' => $post->post_name,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'date' => self::date($post),
            'fields' => self::fields($post),
        ];
        $managedBy = self::managedBy($post);
        if ($managedBy !== null) {
            $summary['managedBy'] = $managedBy;
        }
        if (self::isProtected($post)) {
            $summary['protected'] = true;
        }

        return $summary;
    }

    /**
     * Motivo per cui l'elemento sta in skipped invece che in items, o null.
     *
     * @param list<string> $ambiguous slug ambigui del tipo
     * @return array{type:string,id:int,slug:string,code:string,message:string}|null
     */
    private static function skipReason(WP_Post $post, array $ambiguous): ?array
    {
        if ($post->post_name === '') {
            return self::skip($post, 'missing_slug', __('The item has no slug.', 'nucleo'));
        }
        if (in_array($post->post_name, $ambiguous, true)) {
            return self::skip($post, 'ambiguous_slug', __('Another item of this type has the same slug.', 'nucleo'));
        }

        return null;
    }

    /** @return array{type:string,id:int,slug:string,code:string,message:string} */
    private static function skip(WP_Post $post, string $code, string $message): array
    {
        return ['type' => $post->post_type, 'id' => $post->ID, 'slug' => $post->post_name, 'code' => $code, 'message' => $message];
    }

    /**
     * Slug presenti più di una volta nel tipo, controllati nel database (anche fuori dalla pagina corrente).
     *
     * @return list<string>
     */
    private static function ambiguousSlugs(string $type): array
    {
        global $wpdb;

        return array_values(array_map('strval', $wpdb->get_col($wpdb->prepare(
            "SELECT post_name FROM {$wpdb->posts} WHERE post_type = %s AND post_name <> '' AND post_status NOT IN ('trash', 'auto-draft') GROUP BY post_name HAVING COUNT(*) > 1",
            $type,
        ))));
    }

    /**
     * Precarica termini e miniature della pagina prima di costruire gli elementi.
     *
     * @param list<WP_Post> $posts
     */
    private static function primeCaches(WP_Query $query, array $posts): void
    {
        update_post_thumbnail_cache($query);

        $idsByType = [];
        foreach ($posts as $post) {
            $idsByType[$post->post_type][] = $post->ID;
        }
        foreach ($idsByType as $type => $ids) {
            update_object_term_cache($ids, (string) $type);
        }
    }

    /** @return list<string> */
    private static function listTypes(mixed $type): array
    {
        if ($type === null || $type === '') {
            return array_values(array_filter(ContentTypes::types(), self::canEditType(...)));
        }

        return is_string($type) && ContentTypes::allowsListType($type) && self::canEditType($type) ? [$type] : [];
    }

    private static function canEditType(string $type): bool
    {
        $object = get_post_type_object($type);

        return $object instanceof WP_Post_Type && current_user_can($object->cap->edit_posts);
    }

    /** @return array{0:string,1:string}|null tassonomia e slug */
    private static function parseTerm(string $term): ?array
    {
        [$taxonomy, $slug] = array_pad(explode('/', $term, 2), 2, '');
        if ($taxonomy === '' || $slug === '' || !ContentTypes::allowsTaxonomy($taxonomy)) {
            return null;
        }

        return [$taxonomy, $slug];
    }

    /** @return array<string,list<string>> */
    private static function terms(WP_Post $post): array
    {
        $terms = [];
        foreach (self::taxonomiesOf($post->post_type) as $taxonomy) {
            $slugs = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'slugs']);
            $slugs = is_array($slugs) ? array_values(array_map('strval', $slugs)) : [];
            sort($slugs, SORT_STRING);
            $terms[$taxonomy] = $slugs;
        }

        return $terms;
    }

    /** @return array<string,mixed> */
    private static function fields(WP_Post $post): array
    {
        return ContentRefs::exportFields(FieldValues::fields('post', $post->ID), FieldValues::get('post', $post->ID));
    }

    /** @return list<string> tassonomie ammesse del tipo */
    private static function taxonomiesOf(string $type): array
    {
        return array_values(array_filter(get_object_taxonomies($type), ContentTypes::allowsTaxonomy(...)));
    }

    private static function isHierarchical(string $type): bool
    {
        return get_post_type_object($type)?->hierarchical === true;
    }

    private static function isProtected(WP_Post $post): bool
    {
        return in_array($post->ID, ContentTypes::protectedIds(), true);
    }

    private static function parentSlug(WP_Post $post): ?string
    {
        if ($post->post_parent <= 0) {
            return null;
        }
        $parent = get_post($post->post_parent);

        return $parent instanceof WP_Post && $parent->post_name !== '' ? $parent->post_name : null;
    }

    /** Percorso del documento Nucleo, o null se l'elemento non ne ha uno. */
    private static function managedBy(WP_Post $post): ?string
    {
        return Services::documents()->get($post->ID) === null ? null : SiteExporter::pathOf($post);
    }

    /** Data locale nel formato Y-m-d\TH:i:s. */
    private static function date(WP_Post $post): string
    {
        return str_replace(' ', 'T', $post->post_date);
    }
}
