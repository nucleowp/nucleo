<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

final class HeadingRenderer implements Renderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        $level = $node->settings['level'] ?? 2;
        if (!is_int($level) || $level < 1 || $level > 6) {
            $level = 2;
        }

        $class = htmlspecialchars(trim($ctx->classes($node)), ENT_QUOTES, 'UTF-8');
        $raw = $node->content['text'] ?? '';
        $text = $ctx->richText(is_string($raw) ? $raw : '');

        return '<h' . $level . ' class="' . $class . '">' . $text . '</h' . $level . '>';
    }
}
