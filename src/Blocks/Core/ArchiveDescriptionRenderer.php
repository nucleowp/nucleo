<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\DynamicRenderer;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

final class ArchiveDescriptionRenderer implements Renderer, DynamicRenderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        return $ctx->slot('nc/archive-description', [
            'class' => trim($ctx->classes($node)),
        ], '');
    }

    public function renderSlot(array $data): string
    {
        $class = is_string($data['class'] ?? null) ? $data['class'] : '';

        $description = wp_kses_post(get_the_archive_description());
        if ($description === '') {
            return '';
        }

        return '<div class="' . esc_attr($class) . '">' . $description . '</div>';
    }
}
