<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

/**
 * Markup (contratto tra renderer, style.css e view.js):
 *
 * <nav class="nc-menu … nc-menu--{horizontal|vertical} [nc-menu--dropdown] [nc-menu--offcanvas [nc-menu--bp-{lg|xl}] [nc-menu--panel-right]]" aria-label="{nome menu}">
 *   [solo offcanvas] <button type="button" class="nc-menu__toggle[ nc-menu__toggle--icon]" aria-expanded="false" aria-controls="{listId}"[ aria-label="{etichetta}"]>
 *       testo: {etichetta} | icona: <span class="nc-menu__icon nc-menu__icon--menu">svg "menu"</span><span class="nc-menu__icon nc-menu__icon--close">svg "close"</span></button>
 *   <ul class="nc-menu__list"[ id="{listId}"]>
 *     <li class="nc-menu__item[ nc-menu__item--has-children]">
 *       <a class="nc-menu__link" href="…" data-nc-mi="{id voce}"[ data-nc-mt="post:{id}|term:{id}"][ data-nc-ma="{id antenati}"]>{titolo}</a>
 *       [dropdown e figli] <button type="button" class="nc-menu__sub-toggle" aria-expanded="false" aria-controls="{listId}-sub-{id voce}">
 *           <span class="nc-menu__sr">{Submenu: titolo}</span> svg chevron (aria-hidden)</button>
 *       [mega panel]
 *       [figli] <ul class="nc-menu__submenu" id="{listId}-sub-{id voce}"> … </ul>
 *
 * Senza le impostazioni nuove (submenus, toggle, breakpoint, panelSide) l'aspetto resta quello della versione 1.
 * Voce corrente: MenuCurrent a runtime (aria-current="page", classi is-current e is-ancestor sul link).
 */
final class MenuRenderer implements Renderer
{
    private const CHEVRON = '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="m6 9 6 6 6-6"/></svg>';

    public function render(Node $node, RenderContext $ctx): string
    {
        $menuId = $node->settings['menu'] ?? 0;
        if (!is_int($menuId) || $menuId <= 0) {
            return '';
        }

        $menu = wp_get_nav_menu_object($menuId);
        if (!$menu) {
            return '';
        }

        $items = wp_get_nav_menu_items($menu->term_id);
        if (!is_array($items) || $items === []) {
            return '';
        }

        // Titolo e URL sono proprieta' aggiunte da WordPress alla voce: si leggono come array.
        $children = [];
        foreach ($items as $item) {
            $row = get_object_vars($item);
            $children[(int) ($row['menu_item_parent'] ?? 0)][] = [
                'id' => (int) ($row['ID'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'url' => (string) ($row['url'] ?? ''),
                'mt' => $this->objectKey($row),
            ];
        }

        // Un pannello mega per voce: il figlio nc/mega-panel con settings.item uguale all'id della voce.
        $panels = [];
        foreach ($node->children as $child) {
            if ($child->type === 'nc/mega-panel' && isset($child->settings['item'])) {
                $panels[(int) $child->settings['item']] ??= $child;
            }
        }

        $orientation = ($node->settings['orientation'] ?? 'horizontal') === 'vertical' ? 'vertical' : 'horizontal';
        $offcanvas = ($node->settings['mobile'] ?? 'none') === 'offcanvas';
        $dropdown = ($node->settings['submenus'] ?? 'nested') === 'dropdown';
        $breakpoint = $node->settings['breakpoint'] ?? 'md';
        $listId = 'nc-menu-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $node->id);

        $modifiers = ['nc-menu--' . $orientation];
        if ($dropdown) {
            $modifiers[] = 'nc-menu--dropdown';
        }
        if ($offcanvas) {
            $modifiers[] = 'nc-menu--offcanvas';
            if (in_array($breakpoint, ['lg', 'xl'], true)) {
                $modifiers[] = 'nc-menu--bp-' . $breakpoint;
            }
            if (($node->settings['panelSide'] ?? 'left') === 'right') {
                $modifiers[] = 'nc-menu--panel-right';
            }
        }
        $class = trim($ctx->classes($node) . ' ' . implode(' ', $modifiers));

        $toggle = $offcanvas ? $this->toggle($node, $listId) : '';

        return '<nav class="' . esc_attr($class) . '" aria-label="' . esc_attr($menu->name) . '">'
            . $toggle . $this->list($children, 0, $panels, $ctx, $listId, $dropdown, $offcanvas, []) . '</nav>';
    }

    /** @param array<string,mixed> $row */
    private function objectKey(array $row): ?string
    {
        $object = (int) ($row['object_id'] ?? 0);

        return match ((string) ($row['type'] ?? '')) {
            'post_type' => 'post:' . $object,
            'taxonomy' => 'term:' . $object,
            default => null,
        };
    }

    private function toggle(Node $node, string $listId): string
    {
        $raw = $node->content['toggleLabel'] ?? '';
        $label = is_string($raw) ? trim($raw) : '';
        if ($label === '') {
            $label = __('Menu', 'nucleo');
        }

        $controls = ' aria-expanded="false" aria-controls="' . esc_attr($listId) . '"';
        if (($node->settings['toggle'] ?? 'text') !== 'icon') {
            return '<button type="button" class="nc-menu__toggle"' . $controls . '>' . esc_html($label) . '</button>';
        }

        return '<button type="button" class="nc-menu__toggle nc-menu__toggle--icon"' . $controls
            . ' aria-label="' . esc_attr($label) . '">' . '<span class="nc-menu__icon nc-menu__icon--menu">' . IconRenderer::svg('menu') . '</span><span class="nc-menu__icon nc-menu__icon--close">' . IconRenderer::svg('close') . '</span></button>';
    }

    /**
     * @param array<int,list<array{id:int,title:string,url:string,mt:?string}>> $children
     * @param array<int,Node> $panels
     * @param list<int> $ancestors id delle voci antenate, dalla più vicina alla radice
     */
    private function list(
        array $children,
        int $parent,
        array $panels,
        RenderContext $ctx,
        string $listId,
        bool $dropdown,
        bool $offcanvas,
        array $ancestors,
    ): string {
        if (!isset($children[$parent])) {
            return '';
        }

        $out = '';
        foreach ($children[$parent] as $item) {
            $hasChildren = isset($children[$item['id']]);
            $sub = $this->list($children, $item['id'], $panels, $ctx, $listId, $dropdown, $offcanvas, [...$ancestors, $item['id']]);

            $out .= '<li class="nc-menu__item' . ($hasChildren ? ' nc-menu__item--has-children' : '') . '">'
                . '<a class="nc-menu__link" href="' . esc_url($item['url']) . '" data-nc-mi="' . $item['id'] . '"'
                . ($item['mt'] === null ? '' : ' data-nc-mt="' . esc_attr($item['mt']) . '"')
                . ($ancestors === [] ? '' : ' data-nc-ma="' . esc_attr(implode(' ', $ancestors)) . '"')
                . '>' . esc_html($item['title']) . '</a>'
                . ($dropdown && $hasChildren ? $this->subToggle($listId, $item) : '')
                . $this->panel($panels, $item['id'], $ctx)
                . $sub . '</li>';
        }

        if ($parent === 0) {
            $class = 'nc-menu__list';
            $attr = $offcanvas ? ' id="' . esc_attr($listId) . '"' : '';
        } else {
            $class = 'nc-menu__submenu';
            $attr = $dropdown ? ' id="' . esc_attr($listId . '-sub-' . $parent) . '"' : '';
        }

        return '<ul class="' . $class . '"' . $attr . '>' . $out . '</ul>';
    }

    /** @param array{id:int,title:string,url:string,mt:?string} $item */
    private function subToggle(string $listId, array $item): string
    {
        return '<button type="button" class="nc-menu__sub-toggle" aria-expanded="false" aria-controls="'
            . esc_attr($listId . '-sub-' . $item['id']) . '">'
            // translators: %s: title of the menu item that opens the submenu.
            . '<span class="nc-menu__sr">' . esc_html(sprintf(__('Submenu: %s', 'nucleo'), $item['title'])) . '</span>'
            . self::CHEVRON . '</button>';
    }

    /** @param array<int,Node> $panels */
    private function panel(array $panels, int $itemId, RenderContext $ctx): string
    {
        $panel = $panels[$itemId] ?? null;

        return $panel === null ? '' : (new MegaPanelRenderer())->render($panel, $ctx);
    }
}
