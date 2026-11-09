<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

final class TextRenderer implements Renderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        $class = htmlspecialchars(trim($ctx->classes($node)), ENT_QUOTES, 'UTF-8');
        $raw = $node->content['html'] ?? '';
        $html = $ctx->richText(is_string($raw) ? $raw : '');

        return '<div class="' . $class . '">' . $html . '</div>';
    }
}
