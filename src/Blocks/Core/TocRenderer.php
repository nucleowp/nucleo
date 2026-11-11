<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

/** Indice nc/toc: <nav> con le voci come figli; il label (translatable) è il nome accessibile della lista. */
final class TocRenderer implements Renderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        $raw = $node->content['label'] ?? null;
        $label = is_string($raw) ? trim($raw) : '';
        $aria = $label === '' ? '' : ' aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"';

        $class = htmlspecialchars(trim($ctx->classes($node)), ENT_QUOTES, 'UTF-8');

        return '<nav class="' . $class . '"' . $aria . '>' . $ctx->renderChildren($node) . '</nav>';
    }
}
