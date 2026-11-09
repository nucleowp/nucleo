<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

use Nucleo\Blocks\DynamicRenderer;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;
use WP_Post;

final class PostContentRenderer implements Renderer, DynamicRenderer
{
    /** Guardia statica: the_content puo' richiamare il filtro che riempie lo slot. */
    private static bool $rendering = false;

    public function render(Node $node, RenderContext $ctx): string
    {
        return $ctx->slot('nc/post-content', [
            'class' => trim($ctx->classes($node)),
        ], '');
    }

    public function renderSlot(array $data): string
    {
        $post = get_queried_object();
        if (!$post instanceof WP_Post || self::$rendering) {
            return '';
        }

        self::$rendering = true;
        try {
            // HTML gia' prodotto da WordPress e dai suoi filtri: non si riesegue l'escape.
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro core di WordPress applicato al contenuto del post.
            $html = (string) apply_filters('the_content', $post->post_content);
        } finally {
            self::$rendering = false;
        }

        $class = is_string($data['class'] ?? null) ? $data['class'] : '';

        return '<div class="' . esc_attr($class) . '">' . $html . '</div>';
    }
}
