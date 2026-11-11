<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

defined('ABSPATH') || exit;

/**
 * Lookup su WordPress dei riferimenti di LinkRefs: solo contenuti pubblicati di tipi visibili e termini di tassonomie visibili.
 * Un riferimento già risolto tiene il suo bersaglio per id: il link segue il contenuto anche dopo un cambio di slug.
 */
final class LinkTargets
{
    /**
     * @param array{kind:string,type:string,slug:string,fragment:string} $ref
     * @param ?string $known bersaglio della compilazione precedente: "post:<id>" o "term:<id>"
     * @return array{url:string,dep:?string}|null dep è anche il bersaglio da ricordare ("link:post:<id>")
     */
    public static function lookup(array $ref, ?string $known = null): ?array
    {
        if ($ref['kind'] === 'home') {
            return ['url' => home_url('/'), 'dep' => null];
        }
        if ($ref['kind'] === 'term') {
            if (!taxonomy_exists($ref['type']) || !is_taxonomy_viewable($ref['type'])) {
                return null;
            }
            $term = self::knownTerm($known, $ref['type']) ?? get_term_by('slug', $ref['slug'], $ref['type']);
            $term = $term instanceof \WP_Term ? self::translatedTerm($term) : $term;
            $url = $term instanceof \WP_Term ? get_term_link($term) : null;

            return is_string($url) && $term instanceof \WP_Term ? ['url' => $url, 'dep' => 'link:term:' . $term->term_id] : null;
        }

        if (!post_type_exists($ref['type']) || !is_post_type_viewable($ref['type'])) {
            return null;
        }
        $post = self::knownPost($known, $ref['type']) ?? self::findPost($ref['type'], $ref['slug']);
        $post = $post === null ? null : self::translatedPost($post);
        $url = $post === null ? false : get_permalink($post);

        return is_string($url) && $post !== null ? ['url' => $url, 'dep' => 'link:post:' . $post->ID] : null;
    }

    /**
     * Chiave del riferimento nella mappa dei bersagli: senza ancora.
     * @param array{kind:string,type:string,slug:string,fragment:string} $ref
     */
    public static function key(array $ref): string
    {
        return $ref['kind'] . ':' . $ref['type'] . ':' . $ref['slug'];
    }

    private static function knownPost(?string $known, string $type): ?\WP_Post
    {
        if ($known === null || !str_starts_with($known, 'link:post:')) {
            return null;
        }
        $post = get_post((int) substr($known, 10));

        return $post instanceof \WP_Post && $post->post_type === $type && $post->post_status === 'publish' ? $post : null;
    }

    private static function knownTerm(?string $known, string $taxonomy): ?\WP_Term
    {
        if ($known === null || !str_starts_with($known, 'link:term:')) {
            return null;
        }
        $term = get_term((int) substr($known, 10), $taxonomy);

        return $term instanceof \WP_Term ? $term : null;
    }

    /** Traduzione pubblicata del post nella lingua della compilazione, o il post stesso. */
    private static function translatedPost(\WP_Post $post): \WP_Post
    {
        $id = Translated::id($post->ID, $post->post_type);
        $translated = $id !== $post->ID ? get_post($id) : null;

        return $translated instanceof \WP_Post && $translated->post_status === 'publish' ? $translated : $post;
    }

    private static function translatedTerm(\WP_Term $term): \WP_Term
    {
        $id = Translated::id($term->term_id, $term->taxonomy);
        $translated = $id !== $term->term_id ? get_term($id, $term->taxonomy) : null;

        return $translated instanceof \WP_Term ? $translated : $term;
    }

    /** Riferimento da suggerire per un percorso interno ("/chi-siamo/" → "page:chi-siamo"), null se non è un contenuto pubblicato. */
    public static function refFor(string $path): ?string
    {
        $clean = (string) preg_replace('/#.*$/s', '', $path);
        if ($clean === '/') {
            return 'home';
        }
        $id = url_to_postid(home_url($clean));
        $post = $id > 0 ? get_post($id) : null;
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish' || !is_post_type_viewable($post->post_type)) {
            return null;
        }

        $slug = is_post_type_hierarchical($post->post_type) ? get_page_uri($post) : $post->post_name;

        return is_string($slug) && $slug !== '' ? $post->post_type . ':' . $slug : null;
    }

    /**
     * Post pubblicati con lo slug, in tutte le lingue. Query diretta: WPML riporta la ricerca per slug alla lingua
     * corrente in parse_query, che suppress_filters non spegne.
     * @return list<\WP_Post>
     */
    private static function publishedBySlug(string $type, string $name, int $limit): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WP_Query passa dal filtro di lingua di WPML; il risultato serve una volta per compilazione.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_status = 'publish' ORDER BY ID ASC LIMIT %d",
            sanitize_title($name),
            $type,
            $limit,
        ));

        return array_values(array_filter(array_map('get_post', array_map('intval', $ids)), static fn (mixed $p): bool => $p instanceof \WP_Post));
    }

    /** Post pubblicato per tipo e slug; per i tipi gerarchici lo slug può essere un percorso ("servizi/consulenza"). */
    public static function findPost(string $type, string $slug, bool $allLanguages = false): ?\WP_Post
    {
        $hierarchical = is_post_type_hierarchical($type);
        if (!$hierarchical && str_contains($slug, '/')) {
            return null;
        }
        // Solo contenuti pubblicati: get_page_by_path restituirebbe anche una bozza con lo stesso percorso.
        $posts = $allLanguages ? self::publishedBySlug($type, basename($slug), $hierarchical ? 50 : 1) : get_posts([
            'name' => basename($slug),
            'post_type' => $type,
            'post_status' => 'publish',
            'numberposts' => $hierarchical ? -1 : 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]);
        foreach ($posts as $post) {
            if ($post instanceof \WP_Post && (!$hierarchical || get_page_uri($post) === $slug)) {
                return $post;
            }
        }
        // In una traduzione lo slug è spesso quello dell'originale, in un'altra lingua: si cerca in tutte le lingue.
        if (Translated::language() !== null && !$allLanguages) {
            return self::findPost($type, $slug, true);
        }

        return null;
    }
}
