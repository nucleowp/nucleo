<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

final class ContainerRenderer implements Renderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        $modifier = ($node->settings['layout'] ?? 'flex') === 'grid' ? 'grid' : 'flex';

        // Card cliccabile: serve sia href sicuro sia label (il label è il nome accessibile del link).
        $cover = $this->cover($node);

        $class = htmlspecialchars(
            $ctx->classes($node, 'nc-container--' . $modifier, ...($cover === '' ? [] : ['nc-container--linked'])),
            ENT_QUOTES,
            'UTF-8'
        );

        // Ancora per i link interni (#iscriviti): solo minuscole, cifre e trattini, altrimenti nessun id.
        $anchor = $node->settings['anchor'] ?? null;
        $id = is_string($anchor) && preg_match('/^[a-z][a-z0-9-]{0,63}$/', $anchor) === 1 ? ' id="' . $anchor . '"' : '';

        return '<div' . $id . ' class="' . $class . '">' . $cover . $ctx->renderChildren($node) . '</div>';
    }

    /** Link che copre la card (aria-label = settings.label); stringa vuota senza label o con href non sicuro. */
    private function cover(Node $node): string
    {
        $label = $node->settings['label'] ?? null;
        $href = ButtonRenderer::safeHref($node->settings['href'] ?? null);
        if (!is_string($label) || trim($label) === '' || $href === null) {
            return '';
        }

        return '<a class="nc-container__link" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
            . '" aria-label="' . htmlspecialchars(trim($label), ENT_QUOTES, 'UTF-8') . '"></a>';
    }
}
