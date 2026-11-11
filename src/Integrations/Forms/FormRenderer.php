<?php

declare(strict_types=1);

namespace Nucleo\Integrations\Forms;

defined('ABSPATH') || exit;

use Nucleo\Blocks\DynamicRenderer;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

final class FormRenderer implements Renderer, DynamicRenderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        $plugin = $node->settings['plugin'] ?? '';
        $id = $node->settings['id'] ?? '';

        return $ctx->slot('nc/form', [
            'class' => trim($ctx->classes($node)),
            'plugin' => is_string($plugin) ? $plugin : '',
            'id' => is_string($id) ? $id : '',
        ], '');
    }

    /** @param array<string,mixed> $data */
    public function renderSlot(array $data): string
    {
        $plugin = is_string($data['plugin'] ?? null) ? $data['plugin'] : '';
        // Id del modulo del plugin: solo lettere, cifre, trattino e trattino basso, così non esce dallo shortcode.
        $id = is_string($data['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $data['id']) === 1 ? $data['id'] : '';
        $class = is_string($data['class'] ?? null) ? $data['class'] : '';

        $shortcode = match ($plugin) {
            'cf7' => 'contact-form-7 id="' . esc_attr($id) . '"',
            'gravity' => 'gravityform id="' . esc_attr($id) . '" ajax="true"',
            'wpforms' => 'wpforms id="' . esc_attr($id) . '"',
            'fluent' => 'fluentform id="' . esc_attr($id) . '"',
            default => null,
        };
        if ($shortcode === null || $id === '') {
            return '';
        }

        return '<div class="nc-form ' . esc_attr($class) . '">' . do_shortcode('[' . $shortcode . ']') . '</div>';
    }
}
