<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

final class LogoRenderer implements Renderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        $inner = $this->customLogo() ?? esc_html(get_bloginfo('name'));

        return '<a class="' . esc_attr(trim($ctx->classes($node))) . '" href="' . esc_url(home_url('/')) . '">'
            . $inner . '</a>';
    }

    /** Logo personalizzato con dimensioni esplicite, o null se non c'e'. */
    private function customLogo(): ?string
    {
        $id = (int) get_theme_mod('custom_logo', 0);
        if ($id <= 0) {
            return null;
        }

        $src = wp_get_attachment_image_src($id, 'full');
        if ($src === false) {
            return null;
        }

        $html = wp_get_attachment_image($id, 'full', false, [
            'width' => (string) $src[1],
            'height' => (string) $src[2],
        ]);

        return $html !== '' ? $html : null;
    }
}
