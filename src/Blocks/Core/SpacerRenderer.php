<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

final class SpacerRenderer implements Renderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        $class = htmlspecialchars($ctx->classes($node), ENT_QUOTES, 'UTF-8');

        return '<div class="' . $class . '" aria-hidden="true"></div>';
    }
}
