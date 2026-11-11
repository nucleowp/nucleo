<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

/**
 * Figli di nc/collection (card, empty, count, reset). Li dispone il renderer della collezione:
 * da soli non disegnano nulla.
 */
final class CollectionPartRenderer implements Renderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        return '';
    }
}
