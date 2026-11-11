<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

final class ButtonRenderer implements Renderer
{
    private const VARIANTS = ['primary', 'secondary', 'link'];

    public function render(Node $node, RenderContext $ctx): string
    {
        $variant = $node->settings['variant'] ?? 'primary';
        if (!is_string($variant) || !in_array($variant, self::VARIANTS, true)) {
            $variant = 'primary';
        }

        $class = $this->esc(trim($ctx->classes($node, 'nc-button--' . $variant)));
        $raw = $node->content['text'] ?? '';
        $text = $this->esc(is_string($raw) ? $raw : '');

        $href = self::safeHref($node->settings['href'] ?? null);
        if ($href === null) {
            return '<button type="button" class="' . $class . '">' . $text . '</button>';
        }

        $target = ($node->settings['newTab'] ?? false) === true ? ' target="_blank" rel="noopener"' : '';

        return '<a class="' . $class . '" href="' . $this->esc($href) . '"' . $target . '>' . $text . '</a>';
    }

    /** Restituisce l'href se il suo schema e sicuro, altrimenti null (il blocco diventa un pulsante). */
    public static function safeHref(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        // Browser ignorano tab, a capo e spazi nello schema: li rimuovo prima del controllo.
        $normalized = preg_replace('/[\x00-\x20\x7f]+/', '', $trimmed) ?? '';
        if (preg_match('/^(javascript|data|vbscript):/i', $normalized) === 1) {
            return null;
        }

        return $trimmed;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
