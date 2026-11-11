<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\DynamicRenderer;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

final class SearchRenderer implements Renderer, DynamicRenderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        return $ctx->slot('nc/search', [
            'class' => trim($ctx->classes($node)),
            // Solo i testi del documento: i predefiniti si traducono a ogni richiesta in renderSlot.
            'label' => self::text($node->content['label'] ?? null, ''),
            'button' => self::text($node->content['buttonLabel'] ?? null, ''),
            'placeholder' => self::text($node->content['placeholder'] ?? null, ''),
            'hideLabel' => ($node->settings['hideLabel'] ?? false) === true,
        ], '');
    }

    public function renderSlot(array $data): string
    {
        $class = is_string($data['class'] ?? null) ? $data['class'] : '';
        $label = is_string($data['label'] ?? null) && $data['label'] !== '' ? $data['label'] : __('Search', 'nucleo');
        // Senza testo nel documento (o slot compilato prima di buttonLabel) il pulsante usa il testo tradotto.
        $button = is_string($data['button'] ?? null) && $data['button'] !== '' ? $data['button'] : __('Search', 'nucleo');
        $placeholder = is_string($data['placeholder'] ?? null) ? $data['placeholder'] : '';
        $labelClass = ($data['hideLabel'] ?? false) === true ? 'nc-search__label screen-reader-text' : 'nc-search__label';
        $id = wp_unique_id('nc-search-');

        return '<form role="search" method="get" action="' . esc_url(home_url('/')) . '" class="' . esc_attr($class) . '">'
            . '<label class="' . $labelClass . '" for="' . esc_attr($id) . '">' . esc_html($label) . '</label>'
            . '<input class="nc-search__field" type="search" id="' . esc_attr($id) . '" name="s" value="' . esc_attr(get_search_query()) . '"'
            . ($placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '') . '>'
            . '<button class="nc-search__button" type="submit">' . esc_html($button) . '</button>'
            . '</form>';
    }

    private static function text(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
