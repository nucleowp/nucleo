<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;
use Nucleo\Site\SiteIcons;

final class IconRenderer implements Renderer
{
    /** Nomi del set interno: un'icona del sito non può usarne uno. */
    /** Set locale: disegni su griglia 24x24, riempimento none e tratto currentColor. */
    private const PATHS = [
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'phone' => '<path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'map-pin' => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'star' => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'minus' => '<path d="M5 12h14"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'truck' => '<path d="M3 6h11v10H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'wrench' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.5-2.5z"/>',
        'shield' => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'external-link' => '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'car' => '<path d="M3 13l2-5a2 2 0 0 1 1.9-1.4h10.2A2 2 0 0 1 19 8l2 5v4H3z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
        'bus' => '<rect x="4" y="3" width="16" height="15" rx="2"/><path d="M4 11h16M8 18v3M16 18v3"/>',
        'tractor' => '<circle cx="7" cy="16" r="3"/><circle cx="18" cy="18" r="2"/><path d="M4 13V7h4l2 6h5v3M14 9h3l2 4"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'gauge' => '<path d="M4 16a8 8 0 1 1 16 0"/><path d="M12 16l4-4"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-14.9-3M4 13a8 8 0 0 0 14.9 3"/><path d="M5 4v4h4M19 20v-4h-4"/>',
    ];

    /** @return list<string> nomi delle icone del set locale */
    public static function names(): array
    {
        return array_keys(self::PATHS);
    }

    public function render(Node $node, RenderContext $ctx): string
    {
        $name = $node->settings['name'] ?? '';
        $label = $node->content['label'] ?? null;

        return self::build(
            is_string($name) ? $name : '',
            is_string($label) && trim($label) !== '' ? trim($label) : null,
            $ctx->classes($node),
        );
    }

    /** SVG inline dell'icona: del sito se ne esiste una con questo nome, altrimenti del set interno; stringa vuota se manca. */
    public static function svg(string $name, ?string $label = null): string
    {
        return self::build($name, $label, '');
    }

    private static function build(string $name, ?string $label, string $class): string
    {
        $attrs = '';
        $cls = trim($class);
        if ($cls !== '') {
            $attrs .= ' class="' . self::esc($cls) . '"';
        }
        $attrs .= $label === null
            ? ' aria-hidden="true"'
            : ' role="img" aria-label="' . self::esc($label) . '"';

        // Un'icona del sito con lo stesso nome sostituisce quella interna, anche dentro gli altri blocchi (svg()).
        $site = SiteIcons::render($name, $attrs);
        if ($site !== null || !isset(self::PATHS[$name])) {
            return $site ?? '';
        }

        return '<svg' . $attrs
            . ' viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
            . self::PATHS[$name]
            . '</svg>';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
