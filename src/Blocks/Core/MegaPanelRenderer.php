<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

final class MegaPanelRenderer implements Renderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        $class = esc_attr($ctx->classes($node, 'nc-mega'));

        return '<div class="' . $class . '">' . $ctx->renderChildren($node) . '</div>';
    }
}
