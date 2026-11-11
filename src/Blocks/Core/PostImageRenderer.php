<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\DynamicRenderer;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;
use Nucleo\Media\ImageFormats;
use WP_Post;

final class PostImageRenderer implements Renderer, DynamicRenderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        $size = $node->settings['size'] ?? 'large';
        $formats = ($node->settings['formats'] ?? 'auto') === 'off' ? 'off' : 'auto';

        return $ctx->slot('nc/post-image', [
            'class' => trim($ctx->classes($node)),
            'size' => is_string($size) ? $size : 'large',
            'priority' => ($node->settings['priority'] ?? false) === true,
            'formats' => $formats,
        ], '');
    }

    public function renderSlot(array $data): string
    {
        // Dentro una scheda vale il post dell'elemento, altrimenti quello della richiesta.
        $post = \Nucleo\Runtime\Loop::post() ?? get_queried_object();
        if (!$post instanceof WP_Post) {
            return '';
        }

        $id = (int) get_post_thumbnail_id($post);
        if ($id <= 0) {
            return '';
        }

        $size = $data['size'] ?? 'large';
        if (!is_string($size) || preg_match('/^[a-z0-9_-]+$/', $size) !== 1) {
            $size = 'large';
        }

        $attrs = [];
        $class = is_string($data['class'] ?? null) ? $data['class'] : '';
        if ($class !== '') {
            $attrs['class'] = $class;
        }
        if (($data['priority'] ?? false) === true) {
            $attrs['fetchpriority'] = 'high';
            $attrs['loading'] = 'eager';
        } else {
            $attrs['loading'] = 'lazy';
        }

        $sources = ($data['formats'] ?? 'auto') === 'off' ? [] : ImageFormats::sources($id);
        $sizes = function_exists('wp_get_attachment_image_sizes') ? wp_get_attachment_image_sizes($id, $size) : false;

        // Markup di WordPress: gli attributi sono gia' escapati da wp_get_attachment_image.
        return Picture::wrap(
            wp_get_attachment_image($id, $size, false, $attrs),
            $sources,
            is_string($sizes) ? $sizes : ''
        );
    }
}
