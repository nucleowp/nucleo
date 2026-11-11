<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\DynamicRenderer;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

final class PaginationRenderer implements Renderer, DynamicRenderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        return $ctx->slot('nc/pagination', [
            'class' => trim($ctx->classes($node)),
        ], '');
    }

    public function renderSlot(array $data): string
    {
        $class = is_string($data['class'] ?? null) ? $data['class'] : '';

        // Con una sola pagina paginate_links() restituisce null: nessun output.
        $links = paginate_links();
        if (!is_string($links) || $links === '') {
            return '';
        }

        return '<nav class="' . esc_attr($class) . '" aria-label="' . esc_attr__('Pagination', 'nucleo') . '">' . $links . '</nav>';
    }
}
