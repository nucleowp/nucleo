<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

use Nucleo\Blocks\DynamicRenderer;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;
use WP_Post;

final class PostTitleRenderer implements Renderer, DynamicRenderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        $tag = $node->settings['tag'] ?? 'h1';

        return $ctx->slot('nc/post-title', [
            'class' => trim($ctx->classes($node)),
            'tag' => is_string($tag) ? $tag : 'h1',
            'link' => ($node->settings['link'] ?? false) === true,
        ], '');
    }

    public function renderSlot(array $data): string
    {
        // Dentro una scheda vale il post dell'elemento, altrimenti quello della richiesta.
        $post = \Nucleo\Runtime\Loop::post() ?? get_queried_object();
        if (!$post instanceof WP_Post) {
            return '';
        }

        $tag = $data['tag'] ?? 'h1';
        if (!in_array($tag, ['h1', 'h2', 'p'], true)) {
            $tag = 'h1';
        }

        $class = is_string($data['class'] ?? null) ? $data['class'] : '';
        $title = esc_html(get_the_title($post));
        if (($data['link'] ?? false) === true) {
            $title = '<a href="' . esc_url((string) get_permalink($post)) . '">' . $title . '</a>';
        }

        return '<' . $tag . ' class="' . esc_attr($class) . '">' . $title . '</' . $tag . '>';
    }
}
