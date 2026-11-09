<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

use Nucleo\Blocks\DynamicRenderer;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

final class ArchiveTitleRenderer implements Renderer, DynamicRenderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        return $ctx->slot('nc/archive-title', [
            'class' => trim($ctx->classes($node)),
            'level' => (int) ($node->settings['level'] ?? 1),
        ], '');
    }

    public function renderSlot(array $data): string
    {
        $class = is_string($data['class'] ?? null) ? $data['class'] : '';
        $level = is_int($data['level'] ?? null) ? $data['level'] : 1;
        if ($level < 1 || $level > 3) {
            $level = 1;
        }

        $title = $this->title();
        if ($title === '') {
            return '';
        }

        return '<h' . $level . ' class="' . esc_attr($class) . '">' . esc_html($title) . '</h' . $level . '>';
    }

    /** Titolo senza prefisso "Categoria:"; con la ricerca, la query. */
    private function title(): string
    {
        if (is_search()) {
            /* translators: %s: testo cercato. */
            return sprintf(__('Results for: %s', 'nucleo'), get_search_query());
        }

        add_filter('get_the_archive_title_prefix', '__return_empty_string');
        $title = get_the_archive_title();
        remove_filter('get_the_archive_title_prefix', '__return_empty_string');

        return $title;
    }
}
