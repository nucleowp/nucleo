<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

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
            $url = $term instanceof \WP_Term ? get_term_link($term) : null;

            return is_string($url) && $term instanceof \WP_Term ? ['url' => $url, 'dep' => 'link:term:' . $term->term_id] : null;
        }

        if (!post_type_exists($ref['type']) || !is_post_type_viewable($ref['type'])) {
            return null;
        }
        $post = self::knownPost($known, $ref['type']) ?? self::findPost($ref['type'], $ref['slug']);
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

    /** Post pubblicato per tipo e slug; per i tipi gerarchici lo slug può essere un percorso ("servizi/consulenza"). */
    public static function findPost(string $type, string $slug): ?\WP_Post
    {
        $hierarchical = is_post_type_hierarchical($type);
        if (!$hierarchical && str_contains($slug, '/')) {
            return null;
        }
        // Solo contenuti pubblicati: get_page_by_path restituirebbe anche una bozza con lo stesso percorso.
        $posts = get_posts([
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

        return null;
    }
}
