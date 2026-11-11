<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

final class SectionRenderer implements Renderer
{
    private const TAGS = ['section', 'header', 'footer', 'main', 'div', 'aside', 'nav'];

    public function render(Node $node, RenderContext $ctx): string
    {
        $tag = $node->settings['tag'] ?? 'section';
        if (!is_string($tag) || !in_array($tag, self::TAGS, true)) {
            $tag = 'section';
        }

        $class = htmlspecialchars($ctx->classes($node), ENT_QUOTES, 'UTF-8');

        // Ancora per i link interni (#iscriviti): solo minuscole, cifre e trattini, altrimenti nessun id.
        $anchor = $node->settings['anchor'] ?? null;
        $id = is_string($anchor) && preg_match('/^[a-z][a-z0-9-]{0,63}$/', $anchor) === 1 ? ' id="' . $anchor . '"' : '';

        return '<' . $tag . $id . ' class="' . $class . '"><div class="nc-section__inner">'
            . $ctx->renderChildren($node)
            . '</div></' . $tag . '>';
    }
}
