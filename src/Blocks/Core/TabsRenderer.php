<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

/** Schede accessibili: senza JavaScript tutti i pannelli restano visibili; view.js attiva la navigazione. */
final class TabsRenderer implements Renderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        $items = $node->content['items'] ?? [];
        $tabs = '';
        $panels = '';
        $index = 0;
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = is_string($item['title'] ?? null) ? $item['title'] : '';
            $body = is_string($item['html'] ?? null) ? $item['html'] : '';

            $tabId = $node->id . '-tab-' . $index;
            $panelId = $node->id . '-panel-' . $index;
            $selected = $index === 0;

            $tabs .= '<button type="button" class="nc-tabs__tab" role="tab"'
                . ' id="' . self::escape($tabId) . '"'
                . ' aria-controls="' . self::escape($panelId) . '"'
                . ' aria-selected="' . ($selected ? 'true' : 'false') . '"'
                . ' tabindex="' . ($selected ? '0' : '-1') . '">'
                . self::escape($title)
                . '</button>';

            $panels .= '<div class="nc-tabs__panel" role="tabpanel"'
                . ' id="' . self::escape($panelId) . '"'
                . ' aria-labelledby="' . self::escape($tabId) . '">'
                . $ctx->richText($body)
                . '</div>';

            $index++;
        }

        if ($index === 0) {
            return '';
        }

        $class = self::escape($ctx->classes($node));

        return '<div class="' . $class . '">'
            . '<div class="nc-tabs__list" role="tablist">' . $tabs . '</div>'
            . $panels
            . '</div>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
