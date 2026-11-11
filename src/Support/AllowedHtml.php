<?php

declare(strict_types=1);

namespace Nucleo\Support;

defined('ABSPATH') || exit;

/**
 * Elenchi di tag e attributi per wp_kses: l'escaping avviene al momento della stampa, anche per il markup del plugin.
 */
final class AllowedHtml
{
    /** Attributi SVG degli sprite e delle icone, in minuscolo come li confronta wp_kses. */
    private const SVG_ATTRIBUTES = [
        'id', 'class', 'style', 'xmlns', 'viewbox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
        'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'fill', 'fill-rule', 'clip-rule', 'opacity', 'fill-opacity',
        'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-opacity',
        'transform', 'href', 'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform',
        'focusable', 'aria-hidden', 'role', 'preserveaspectratio',
    ];

    private const SVG_ELEMENTS = [
        'svg', 'symbol', 'defs', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'use',
        'lineargradient', 'radialgradient', 'stop', 'title', 'desc',
    ];

    /** Attributi validi su ogni elemento dell'HTML compilato. wp_kses ammette sempre data-* e solo alcuni aria-*: qui gli altri. */
    private const GLOBAL_ATTRIBUTES = [
        'id', 'class', 'style', 'role', 'tabindex', 'hidden', 'title', 'lang', 'dir', 'inert', 'popover',
        'aria-roledescription', 'aria-selected', 'aria-modal', 'aria-pressed', 'aria-haspopup', 'aria-atomic',
        'aria-busy', 'aria-disabled', 'aria-invalid', 'aria-required', 'aria-checked', 'aria-orientation',
        'aria-valuenow', 'aria-valuemin', 'aria-valuemax', 'aria-valuetext', 'aria-posinset', 'aria-setsize',
        'aria-level', 'aria-owns', 'aria-activedescendant', 'aria-autocomplete', 'aria-multiselectable',
        'aria-errormessage', 'aria-readonly', 'aria-sort', 'aria-relevant', 'aria-keyshortcuts', 'aria-placeholder',
    ];

    /** Elementi dell'HTML compilato che wp_kses_post non ammette, con i loro attributi propri. */
    private const COMPILED_EXTRA = [
        'form' => ['action', 'method', 'novalidate', 'autocomplete', 'enctype'],
        'input' => [
            'type', 'name', 'value', 'checked', 'disabled', 'required', 'readonly', 'placeholder', 'autocomplete',
            'inputmode', 'pattern', 'min', 'max', 'step', 'minlength', 'maxlength', 'accept', 'multiple', 'size', 'list',
        ],
        'select' => ['name', 'required', 'disabled', 'multiple', 'autocomplete', 'size'],
        'option' => ['value', 'selected', 'disabled', 'label'],
        'optgroup' => ['label', 'disabled'],
        'textarea' => ['name', 'rows', 'cols', 'required', 'disabled', 'readonly', 'placeholder', 'minlength', 'maxlength', 'autocomplete'],
        'button' => ['type', 'name', 'value', 'disabled', 'form', 'popovertarget', 'popovertargetaction'],
        'label' => ['for'],
        'fieldset' => ['disabled', 'name'],
        'legend' => [],
        'output' => ['for', 'name'],
        'dialog' => ['open', 'closedby'],
        'details' => ['open', 'name'],
        'summary' => [],
        'picture' => [],
        'source' => ['src', 'srcset', 'sizes', 'type', 'media', 'width', 'height'],
        'img' => ['src', 'srcset', 'sizes', 'alt', 'width', 'height', 'loading', 'decoding', 'fetchpriority'],
        'time' => ['datetime'],
        'main' => [],
        'search' => [],
        'a' => ['href', 'target', 'rel', 'download', 'hreflang'],
    ];

    /**
     * Sprite e icone SVG del plugin.
     *
     * @return array<string, array<string, bool>>
     */
    public static function svg(): array
    {
        $attributes = array_fill_keys(self::SVG_ATTRIBUTES, true);

        return array_fill_keys(self::SVG_ELEMENTS, $attributes) + ['span' => ['class' => true, 'aria-hidden' => true]];
    }

    /**
     * HTML prodotto dal compilatore: i tag di wp_kses_post, i form, le icone SVG e gli attributi globali.
     *
     * @return array<string, array<string, bool>>
     */
    public static function compiled(): array
    {
        $allowed = wp_kses_allowed_html('post');
        foreach (self::COMPILED_EXTRA as $tag => $attributes) {
            $allowed[$tag] = ($allowed[$tag] ?? []) + array_fill_keys($attributes, true);
        }
        $allowed += self::svg();

        $global = array_fill_keys(self::GLOBAL_ATTRIBUTES, true);
        foreach ($allowed as $tag => $attributes) {
            $allowed[$tag] = (is_array($attributes) ? $attributes : []) + $global;
        }

        return $allowed;
    }
}
