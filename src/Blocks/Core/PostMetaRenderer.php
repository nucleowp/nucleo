<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

use Nucleo\Blocks\DynamicRenderer;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;
use WP_Post;
use WP_Term;

final class PostMetaRenderer implements Renderer, DynamicRenderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        $fields = $node->settings['fields'] ?? ['date'];
        $terms = ($node->settings['terms'] ?? 'text') === 'chips' ? 'chips' : 'text';

        return $ctx->slot('nc/post-meta', [
            'class' => trim($ctx->classes($node)),
            'fields' => is_array($fields) ? array_values($fields) : ['date'],
            'terms' => $terms,
        ], '');
    }

    public function renderSlot(array $data): string
    {
        // Dentro una scheda vale il post dell'elemento, altrimenti quello della richiesta.
        $post = \Nucleo\Runtime\Loop::post() ?? get_queried_object();
        if (!$post instanceof WP_Post) {
            return '';
        }

        // Slot legacy post.date (senza fields): mostra solo la data.
        $fields = $data['fields'] ?? (($data['field'] ?? null) === 'date' ? ['date'] : []);
        if (!is_array($fields)) {
            return '';
        }
        $terms = ($data['terms'] ?? 'text') === 'chips' ? 'chips' : 'text';

        $items = [];
        foreach ($fields as $field) {
            $html = match ($field) {
                'date' => $this->date($post),
                'author' => $this->author($post),
                'categories' => $this->categories($post),
                default => is_string($field) && str_starts_with($field, 'tax:')
                    ? $this->terms($post, substr($field, 4), $terms)
                    : '',
            };
            if ($html !== '') {
                $items[] = '<span class="nc-post-meta__item">' . $html . '</span>';
            }
        }
        if ($items === []) {
            return '';
        }

        $class = is_string($data['class'] ?? null) ? $data['class'] : '';
        if ($terms === 'chips') {
            $class = trim($class . ' nc-post-meta--chips');
        }

        return '<p class="' . esc_attr($class) . '">' . implode('', $items) . '</p>';
    }

    private function date(WP_Post $post): string
    {
        $iso = (string) get_the_date('c', $post);
        if ($iso === '') {
            return '';
        }

        return '<time datetime="' . esc_attr($iso) . '">' . esc_html((string) get_the_date('', $post)) . '</time>';
    }

    private function author(WP_Post $post): string
    {
        return esc_html((string) get_the_author_meta('display_name', (int) $post->post_author));
    }

    private function categories(WP_Post $post): string
    {
        $links = [];
        foreach (get_the_category($post->ID) as $category) {
            $links[] = '<a href="' . esc_url(get_category_link((int) $category->term_id)) . '">'
                . esc_html($category->name) . '</a>';
        }

        return implode(', ', $links);
    }

    /** Termini del post in una tassonomia; vuoto se la tassonomia non esiste o il post non ne ha. */
    private function terms(WP_Post $post, string $taxonomy, string $mode): string
    {
        // Solo tassonomie pubbliche: product_visibility o tassonomie interne non escono nella pagina.
        if (!taxonomy_exists($taxonomy) || !is_taxonomy_viewable($taxonomy)) {
            return '';
        }
        $list = get_the_terms($post->ID, $taxonomy);
        if (!is_array($list)) {
            return '';
        }

        $links = [];
        foreach ($list as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }
            $link = get_term_link($term);
            if (!is_string($link)) {
                continue;
            }
            $links[] = '<a class="nc-post-meta__term" href="' . esc_url($link) . '">'
                . esc_html($term->name) . '</a>';
        }

        return implode($mode === 'chips' ? '' : ', ', $links);
    }
}
