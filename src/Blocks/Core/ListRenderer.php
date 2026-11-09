<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

final class ListRenderer implements Renderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        $icon = $node->settings['icon'] ?? null;
        $icon = is_string($icon) ? $icon : '';

        $items = $node->content['items'] ?? [];
        $class = htmlspecialchars(trim($ctx->classes($node)), ENT_QUOTES, 'UTF-8');

        $html = '<ul class="' . $class . '">';
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_string($item)) {
                    continue;
                }
                $prefix = $icon !== '' ? IconRenderer::svg($icon) : '';
                $html .= '<li>' . $prefix . $ctx->richText($item) . '</li>';
            }
        }

        return $html . '</ul>';
    }
}
