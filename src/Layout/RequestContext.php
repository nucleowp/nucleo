<?php

declare(strict_types=1);

namespace Nucleo\Layout;

/**
 * Contesto della richiesta per LayoutResolver: dalla query principale, o da un URL.
 * Usa solo dati già in cache: nessuna query nuova per la richiesta corrente.
 */
final class RequestContext
{
    /** @return array<string,mixed> */
    public static function current(): array
    {
        $context = self::detect();
        // Pro edition only. Loaded only with WPML, Polylang or a nucleo/current_language filter: two files less otherwise.
        $language = function_exists('pll_current_language') || defined('ICL_SITEPRESS_VERSION') || has_filter('nucleo/current_language')
            ? \Nucleo\Support\Optional::call('Nucleo\\Integrations\\Multilingual', 'current')
            : null;
        if (is_string($language) && $language !== '') {
            $context['language'] = $language;
        }

        return $context;
    }

    /**
     * Contesto del post all'URL indicato; l'URL di casa con home statica è la pagina iniziale.
     *
     * @return array<string,mixed>
     */
    public static function fromUrl(string $url): array
    {
        $frontId = get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0;
        $path = (string) strtok($url, '?');
        $isHome = rtrim($path, '/') === rtrim(home_url('/'), '/');

        $id = url_to_postid($url);
        // La pagina Shop di WooCommerce è l'archivio di product, come in detect().
        if ($id > 0 && function_exists('wc_get_page_id') && $id === (int) wc_get_page_id('shop')) {
            return ['kind' => 'post_type_archive', 'type' => 'product'];
        }
        if ($id === 0) {
            $archive = self::archiveFromQuery($url) ?? ($isHome ? null : self::archiveFromPath($path));
            if ($archive !== null) {
                return $archive;
            }
        }
        if ($id === 0 && $isHome) {
            if ($frontId === 0) {
                return ['kind' => 'blog'];
            }
            $id = $frontId;
        }
        if ($id === 0) {
            return $isHome ? ['kind' => 'blog'] : ['kind' => 'other'];
        }

        $post = get_post($id);
        if (!$post instanceof \WP_Post) {
            return ['kind' => 'other'];
        }

        $context = self::singular($post, $id === $frontId);
        // Fuori dalla richiesta la lingua viene dal post: senza, layout-explain sceglie il layout di un'altra lingua.
        $language = \Nucleo\Support\Optional::call('Nucleo\\Integrations\\Multilingual', 'postLanguage', $id);
        if (is_string($language) && $language !== '') {
            $context['language'] = $language;
        }

        return $context;
    }

    /**
     * Archivio da un URL senza permalink: ?post_type=, ?cat=, ?tag= o la query var di una tassonomia (?product_cat=).
     *
     * @return array<string,mixed>|null
     */
    private static function archiveFromQuery(string $url): ?array
    {
        $query = [];
        wp_parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        if ($query === []) {
            return null;
        }
        $terms = ['category' => $query['cat'] ?? null, 'post_tag' => $query['tag'] ?? null];
        foreach (get_taxonomies(['public' => true], 'objects') as $taxonomy) {
            if (is_string($taxonomy->query_var) && $taxonomy->query_var !== '' && isset($query[$taxonomy->query_var])) {
                $terms[$taxonomy->name] = $query[$taxonomy->query_var];
            }
        }
        foreach ($terms as $name => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            $term = ctype_digit($value) ? get_term((int) $value, $name) : get_term_by('slug', sanitize_title($value), $name);
            if ($term instanceof \WP_Term) {
                return ['kind' => 'term_archive', 'taxonomy' => $term->taxonomy, 'id' => (int) $term->term_id];
            }
        }
        $type = $query['post_type'] ?? null;
        if (is_string($type) && post_type_exists($type)) {
            return ['kind' => 'post_type_archive', 'type' => $type];
        }

        return null;
    }

    /**
     * Archivio all'URL: archivio di un tipo (link di get_post_type_archive_link) o di un termine (slug di riscrittura
     * della tassonomia, poi lo slug del termine). Null se l'URL non è un archivio riconosciuto.
     *
     * @return array<string,mixed>|null
     */
    private static function archiveFromPath(string $path): ?array
    {
        $path = trim((string) wp_parse_url($path, PHP_URL_PATH), '/');
        $home = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home !== '' && str_starts_with($path . '/', $home . '/')) {
            $path = trim(substr($path, strlen($home)), '/');
        }
        // Le pagine successive (/page/2/) appartengono allo stesso archivio.
        $path = (string) preg_replace('#/page/\d+$#', '', $path);
        if ($path === '') {
            return null;
        }

        foreach (get_post_types(['public' => true], 'names') as $type) {
            $link = get_post_type_archive_link($type);
            if (is_string($link) && trim((string) wp_parse_url($link, PHP_URL_PATH), '/') === trim($home . '/' . $path, '/')) {
                return ['kind' => 'post_type_archive', 'type' => $type];
            }
        }
        foreach (get_taxonomies(['public' => true], 'objects') as $taxonomy) {
            $slug = is_array($taxonomy->rewrite) ? trim((string) ($taxonomy->rewrite['slug'] ?? ''), '/') : '';
            if ($slug === '' || !str_starts_with($path, $slug . '/')) {
                continue;
            }
            $segments = explode('/', substr($path, strlen($slug) + 1));
            $term = get_term_by('slug', sanitize_title((string) end($segments)), $taxonomy->name);
            if ($term instanceof \WP_Term) {
                return ['kind' => 'term_archive', 'taxonomy' => $term->taxonomy, 'id' => (int) $term->term_id];
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private static function detect(): array
    {
        if (is_404()) {
            return ['kind' => '404'];
        }
        if (is_search()) {
            return ['kind' => 'search'];
        }
        if (is_singular()) {
            $post = get_queried_object();
            if ($post instanceof \WP_Post) {
                return self::singular($post, is_front_page());
            }
        }
        if (is_home()) {
            return ['kind' => 'blog'];
        }
        if (is_tax() || is_category() || is_tag()) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                return ['kind' => 'term_archive', 'taxonomy' => $term->taxonomy, 'id' => $term->term_id];
            }
        }
        if (is_post_type_archive()) {
            // La pagina Shop di WooCommerce è l'archivio di product, ma l'oggetto interrogato è la pagina Shop:
            // il tipo si legge dalla query.
            $type = get_queried_object();
            $name = $type instanceof \WP_Post_Type ? $type->name : get_query_var('post_type');
            $name = is_array($name) ? reset($name) : $name;
            if (is_string($name) && $name !== '' && post_type_exists($name)) {
                return ['kind' => 'post_type_archive', 'type' => $name];
            }
        }
        if (is_author()) {
            $user = get_queried_object();
            if ($user instanceof \WP_User) {
                return ['kind' => 'author_archive', 'id' => $user->ID];
            }
        }

        return ['kind' => 'other'];
    }

    /** @return array<string,mixed> */
    private static function singular(\WP_Post $post, bool $front): array
    {
        $terms = [];
        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $found = get_the_terms($post, $taxonomy);
            if (!is_array($found)) {
                continue;
            }
            $terms[$taxonomy] = array_values(array_map(static fn (\WP_Term $term): int => (int) $term->term_id, $found));
        }

        $context = [
            'kind' => 'singular',
            'id' => (int) $post->ID,
            'type' => $post->post_type,
            'terms' => $terms,
            'front' => $front,
        ];
        if ((int) $post->post_author > 0) {
            $context['author'] = (int) $post->post_author;
        }
        // Tipo WooCommerce del prodotto: lo slug del termine di product_type (simple, variable, grouped, external).
        if ($post->post_type === 'product' && isset($terms['product_type'][0])) {
            $productType = get_term((int) $terms['product_type'][0]);
            if ($productType instanceof \WP_Term) {
                $context['product_type'] = $productType->slug;
            }
        }

        return $context;
    }
}
