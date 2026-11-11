<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\CardRules;
use Nucleo\Blocks\DynamicRenderer;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\NodeValidator;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;
use Nucleo\Runtime\CardRenderer;
use Nucleo\Runtime\Collection\ContentVersion;
use Nucleo\Runtime\Loop;
use Nucleo\Runtime\Slots;
use Nucleo\Schema\ValidationError;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Elenco di post disegnato al salvataggio. Con un figlio, il figlio è la scheda: si ripete per ogni post
 * con i bind letti dal post. Senza figli, scheda fissa con immagine, titolo ed estratto.
 * Con relatedTo l'elenco è uno slot a runtime: post correlati al post corrente per termine, in cache sul post.
 */
final class QueryRenderer implements Renderer, NodeValidator, DynamicRenderer
{
    private const DEFAULT_PER_PAGE = 6;

    /**
     * Cache dell'HTML di relatedTo. Con un object cache persistente: wp_cache, gruppo CACHE_GROUP.
     * Senza: meta del post corrente, già in memoria nella richiesta (nessuna query in lettura).
     * Chiave stabile: documento e nodo. Valore: ['v' => ContentVersion, 't' => md5 del modello, 'html' => string].
     */
    private const CACHE_META = '_nucleo_related';
    private const CACHE_GROUP = 'nucleo_related';

    public function render(Node $node, RenderContext $ctx): string
    {
        $class = esc_attr(trim($ctx->classes($node)));
        $empty = $this->str($node->settings['empty'] ?? null, __('No content', 'nucleo'));

        $relatedTo = sanitize_key($this->str($node->settings['relatedTo'] ?? null, ''));
        if ($relatedTo !== '') {
            return $ctx->slot('nc/query', [
                'node' => $node->id,
                'class' => trim($ctx->classes($node)),
                'empty' => $empty,
                'query' => $node->settings,
                'template' => $this->template($node, $ctx),
            ], '');
        }

        $posts = $this->posts($node->settings);
        if ($posts === []) {
            return '<p class="' . $class . '">' . esc_html($empty) . '</p>';
        }

        $template = $this->template($node, $ctx);
        $cards = '';
        foreach ($posts as $post) {
            $cards .= $template === null
                ? $this->card($post)
                : '<div class="nc-query__item">' . CardRenderer::render($template, $post) . '</div>';
        }

        return '<div class="' . $class . '">' . $cards . '</div>';
    }

    /** Slot relatedTo: i post correlati si calcolano a ogni richiesta, l'HTML si riusa finché la versione non cambia. */
    public function renderSlot(array $data): string
    {
        $class = esc_attr($this->str($data['class'] ?? null, ''));
        $empty = $this->str($data['empty'] ?? null, '');
        $query = is_array($data['query'] ?? null) ? $data['query'] : [];
        $template = is_array($data['template'] ?? null) ? $data['template'] : null;

        $current = Loop::post() ?? get_queried_object();
        $current = $current instanceof WP_Post ? $current : null;
        if ($current === null) {
            return $this->relatedHtml($this->relatedPosts($query, null), $template, $class, $empty);
        }

        $key = Slots::document() . ':' . $this->str($data['node'] ?? null, '');
        // La lingua entra nella firma: l'HTML contiene date ed etichette tradotte.
        $stamp = ['v' => ContentVersion::get(), 't' => md5((string) wp_json_encode([$query, $template, $class, $empty, determine_locale()]))];
        $cached = $this->cacheGet($current->ID, $key);
        if ($cached !== null && ($cached['v'] ?? null) === $stamp['v'] && ($cached['t'] ?? null) === $stamp['t'] && is_string($cached['html'] ?? null)) {
            return $cached['html'];
        }

        $html = $this->relatedHtml($this->relatedPosts($query, $current), $template, $class, $empty);
        $this->cacheSet($current->ID, $key, $stamp + ['html' => $html]);

        return $html;
    }

    /** @return array<string,mixed>|null */
    private function cacheGet(int $postId, string $key): ?array
    {
        if (wp_using_ext_object_cache()) {
            $hit = wp_cache_get($postId . ':' . $key, self::CACHE_GROUP);

            return is_array($hit) ? $hit : null;
        }
        $all = get_post_meta($postId, self::CACHE_META, true);
        $hit = is_array($all) ? ($all[$key] ?? null) : null;

        return is_array($hit) ? $hit : null;
    }

    /**
     * Scrive la voce e toglie quelle di versioni scadute. Due richieste concorrenti scrivono lo stesso HTML:
     * l'ultima vince e il risultato resta valido.
     * @param array<string,mixed> $entry
     */
    private function cacheSet(int $postId, string $key, array $entry): void
    {
        if (wp_using_ext_object_cache()) {
            wp_cache_set($postId . ':' . $key, $entry, self::CACHE_GROUP, DAY_IN_SECONDS);

            return;
        }
        $all = get_post_meta($postId, self::CACHE_META, true);
        $all = is_array($all) ? $all : [];
        $all = array_filter($all, static fn (mixed $e): bool => is_array($e) && ($e['v'] ?? null) === $entry['v']);
        $all[$key] = $entry;
        update_post_meta($postId, self::CACHE_META, $all);
    }

    /**
     * @param list<WP_Post> $posts
     * @param array<string,mixed>|null $template
     */
    private function relatedHtml(array $posts, ?array $template, string $class, string $empty): string
    {
        if ($posts === []) {
            return $empty === '' ? '' : '<p class="' . $class . '">' . esc_html($empty) . '</p>';
        }

        $cards = '';
        foreach ($posts as $post) {
            $cards .= $template === null
                ? $this->card($post)
                : '<div class="nc-query__item">' . CardRenderer::render($template, $post) . '</div>';
        }

        return '<div class="' . $class . '">' . $cards . '</div>';
    }

    /**
     * Post dello stesso tipo che condividono almeno un termine di relatedTo con $current, escluso $current.
     * Il filtro fisso taxonomy/term resta in AND.
     *
     * @param array<string,mixed> $query
     * @return list<WP_Post>
     */
    private function relatedPosts(array $query, ?WP_Post $current): array
    {
        $relatedTo = sanitize_key($this->str($query['relatedTo'] ?? null, ''));
        if ($current === null || $relatedTo === '' || !taxonomy_exists($relatedTo)) {
            return [];
        }
        // postType "post" è il default dei settings: con relatedTo vale il tipo del post corrente, se usa la tassonomia.
        // Vale il tipo corrente anche quando la tassonomia non appartiene al tipo indicato.
        $postType = sanitize_key($this->str($query['postType'] ?? null, 'post'));
        $currentFits = is_object_in_taxonomy($current->post_type, $relatedTo);
        if (($postType === 'post' && $currentFits) || !is_object_in_taxonomy($postType, $relatedTo)) {
            $query['postType'] = $current->post_type;
        }
        $args = $this->args($query);
        if ($args === null) {
            return [];
        }

        $terms = get_the_terms($current, $relatedTo);
        $ids = [];
        foreach (is_array($terms) ? $terms : [] as $term) {
            if ($term instanceof WP_Term) {
                $ids[] = $term->term_id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $clauses = [['taxonomy' => $relatedTo, 'field' => 'term_id', 'terms' => $ids, 'operator' => 'IN']];
        $fixed = $this->fixedClause($query);
        if ($fixed !== null) {
            $clauses[] = $fixed;
        }
        $args['tax_query'] = count($clauses) > 1 ? ['relation' => 'AND', ...$clauses] : $clauses;
        $args['post__not_in'] = [$current->ID];

        return $this->run($args);
    }

    /**
     * @param array<string,mixed> $settings
     * @return list<WP_Post>
     */
    private function posts(array $settings): array
    {
        $args = $this->args($settings);
        if ($args === null) {
            return [];
        }

        $fixed = $this->fixedClause($settings);
        if ($fixed !== null) {
            $args['tax_query'] = [$fixed];
        }

        return $this->run($args);
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>|null null se il tipo di post non esiste
     */
    private function args(array $settings): ?array
    {
        $postType = sanitize_key($this->str($settings['postType'] ?? null, 'post'));
        if (!post_type_exists($postType)) {
            return null;
        }

        $perPage = $settings['perPage'] ?? self::DEFAULT_PER_PAGE;
        if (!is_int($perPage)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        $perPage = max(1, min(24, $perPage));

        $byTitle = ($settings['orderby'] ?? 'date') === 'title';

        return [
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => $perPage,
            'orderby' => $byTitle ? 'title' : 'date',
            'order' => $byTitle ? 'ASC' : 'DESC',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            // Un post protetto da password non entra negli elenchi: la scheda mostrerebbe estratto e campi.
            'has_password' => false,
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,string>|null
     */
    private function fixedClause(array $settings): ?array
    {
        $taxonomy = sanitize_key($this->str($settings['taxonomy'] ?? null, ''));
        $term = sanitize_title($this->str($settings['term'] ?? null, ''));
        if ($taxonomy === '' || $term === '' || !taxonomy_exists($taxonomy)) {
            return null;
        }

        return ['taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $term];
    }

    /**
     * @param array<string,mixed> $args
     * @return list<WP_Post>
     */
    private function run(array $args): array
    {
        $found = [];
        foreach ((new WP_Query($args))->posts ?? [] as $post) {
            if ($post instanceof WP_Post) {
                $found[] = $post;
            }
        }

        return $found;
    }

    /** @return array<string,mixed>|null */
    private function template(Node $node, RenderContext $ctx): ?array
    {
        $card = $node->children[0] ?? null;

        return $card === null ? null : $ctx->template($card);
    }

    private function card(WP_Post $post): string
    {
        $image = '';
        $thumbId = (int) get_post_thumbnail_id($post);
        if ($thumbId > 0) {
            $image = wp_get_attachment_image($thumbId, 'medium_large', false, ['loading' => 'lazy']);
        }

        return '<article class="nc-query__item">' . $image
            . '<h2><a href="' . esc_url((string) get_permalink($post)) . '">' . esc_html(get_the_title($post)) . '</a></h2>'
            . '<p>' . esc_html(get_the_excerpt($post)) . '</p>'
            . '</article>';
    }

    public function validateNode(array $node, string $path): array
    {
        $children = is_array($node['children'] ?? null) ? array_values($node['children']) : [];
        $errors = [];
        if (count($children) > 1) {
            $errors[] = new ValidationError(
                "$path/children",
                __('nc/query accepts one child: the card', 'nucleo'),
                count($children),
                [],
                __('wrap the card nodes in one nc/container or use a ref to a section', 'nucleo'),
                'card_missing',
            );
        }
        if (is_array($children[0] ?? null)) {
            array_push($errors, ...CardRules::errors($children[0], "$path/children/0"));
        }

        return $errors;
    }

    private function str(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }
}
