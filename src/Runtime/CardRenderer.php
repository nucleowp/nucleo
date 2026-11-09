<?php

declare(strict_types=1);

namespace Nucleo\Runtime;

use WP_Post;
use WP_Term;

/** Disegna una scheda compilata (RenderContext::template) per un elemento: riempie i suoi slot con l'elemento in Loop. */
final class CardRenderer
{
    /**
     * @param array<string,mixed> $template {html, slots}
     * @param WP_Post|WP_Term|array<string,mixed> $item
     */
    public static function render(array $template, WP_Post|WP_Term|array $item): string
    {
        $html = is_string($template['html'] ?? null) ? $template['html'] : '';
        $slots = [];
        foreach (is_array($template['slots'] ?? null) ? $template['slots'] : [] as $i => $slot) {
            if (is_int($i) && is_array($slot) && is_string($slot['block'] ?? null)) {
                $slots[$i] = ['block' => $slot['block'], 'data' => is_array($slot['data'] ?? null) ? $slot['data'] : []];
            }
        }
        if ($slots === []) {
            return $html;
        }

        Loop::push($item);
        try {
            return (new SlotRenderer())->fill($html, $slots, [Slots::class, 'render']);
        } finally {
            Loop::pop();
        }
    }
}
