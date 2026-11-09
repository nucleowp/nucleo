<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

/**
 * Markup (contratto tra renderer, style.css e view.js):
 *
 * <div class="nc-offcanvas … nc-offcanvas--{left|right}" style="--nc-offcanvas-width: {larghezza}">
 *   <button type="button" class="nc-offcanvas__trigger" aria-expanded="false" aria-controls="{panelId}">{etichetta}</button>
 *   <div class="nc-offcanvas__backdrop" aria-hidden="true" hidden></div>
 *   <div class="nc-offcanvas__panel" id="{panelId}" role="dialog" aria-modal="true" aria-label="{etichetta}" tabindex="-1" hidden>
 *     figli
 *   </div>
 * </div>
 *
 * Senza JavaScript il pannello resta nascosto; view.js lo apre dal pulsante.
 */
final class OffcanvasRenderer implements Renderer
{
    private const DEFAULT_WIDTH = '20rem';

    public function render(Node $node, RenderContext $ctx): string
    {
        $raw = $node->settings['label'] ?? null;
        $label = is_string($raw) ? trim($raw) : '';
        if ($label === '') {
            return '';
        }

        $side = ($node->settings['side'] ?? 'left') === 'right' ? 'right' : 'left';
        $width = $this->width($node->settings['width'] ?? null);
        $panelId = 'nc-offcanvas-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $node->id);

        $class = esc_attr($ctx->classes($node, 'nc-offcanvas--' . $side));

        return '<div class="' . $class . '" style="--nc-offcanvas-width: ' . esc_attr($width) . '">'
            . '<button type="button" class="nc-offcanvas__trigger" aria-expanded="false" aria-controls="' . esc_attr($panelId) . '">'
            . esc_html($label) . '</button>'
            . '<div class="nc-offcanvas__backdrop" aria-hidden="true" hidden></div>'
            . '<div class="nc-offcanvas__panel" id="' . esc_attr($panelId) . '" role="dialog" aria-modal="true"'
            . ' aria-label="' . esc_attr($label) . '" tabindex="-1" hidden>'
            . $ctx->renderChildren($node)
            . '</div></div>';
    }

    /** Lunghezza CSS semplice (20rem, 320px, 80vw, 50%); con altro valore usa quella predefinita. */
    private function width(mixed $raw): string
    {
        $value = is_string($raw) ? trim($raw) : '';

        return preg_match('/^\d+(\.\d+)?(rem|em|px|vw|%)$/', $value) === 1 ? $value : self::DEFAULT_WIDTH;
    }
}
