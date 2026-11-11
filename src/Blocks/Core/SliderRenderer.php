<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

/** Carosello: scroll-snap (slide) o dissolvenza (fade). Nessuno script obbligatorio; view.js collega frecce, pallini e autoplay. */
final class SliderRenderer implements Renderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        $label = is_string($node->content['label'] ?? null) && $node->content['label'] !== ''
            ? $node->content['label']
            : __('Gallery', 'nucleo');

        $effect = ($node->settings['effect'] ?? 'slide') === 'fade' ? 'fade' : 'slide';

        $perView = $node->settings['perView'] ?? 1;
        $perView = is_int($perView) ? max(1, min(4, $perView)) : 1;
        if ($effect === 'fade') {
            // Con fade le slide stanno tutte nella stessa cella: perView non ha effetto.
            $perView = 1;
        }

        $arrows = ($node->settings['arrows'] ?? true) === true;

        $autoplay = $node->settings['autoplay'] ?? 0;
        $autoplay = is_int($autoplay) ? max(0, min(30, $autoplay)) : 0;

        $loop = ($node->settings['loop'] ?? true) === true;
        $dots = ($node->settings['dots'] ?? false) === true;
        $pauseOnHover = ($node->settings['pauseOnHover'] ?? true) === true;

        $speed = $node->settings['speed'] ?? 500;
        $speed = is_int($speed) ? max(100, min(3000, $speed)) : 500;

        $controlsPosition = $node->settings['controlsPosition'] ?? 'below';
        $controlsPosition = in_array($controlsPosition, ['bottom-left', 'bottom-right', 'bottom-center'], true)
            ? $controlsPosition
            : 'below';

        $total = count($node->children);
        $slides = '';
        foreach ($node->children as $index => $child) {
            // Il renderer dei figli lavora su un nodo contenitore: si passa il singolo figlio per avvolgerlo.
            $slides .= '<div class="nc-slider__slide' . ($index === 0 ? ' is-active' : '') . '"'
                . ' role="group" aria-roledescription="slide"'
                . ' aria-label="' . ($index + 1) . ' / ' . $total . '">'
                . $ctx->renderChildren(new Node($node->id, $node->type, children: [$child]))
                . '</div>';
        }

        $dotsHtml = '';
        if ($dots && $total > 0) {
            $dotsHtml = '<div class="nc-slider__dots">';
            for ($index = 0; $index < $total; $index++) {
                $dotsHtml .= '<button type="button" class="nc-slider__dot' . ($index === 0 ? ' is-active' : '') . '"'
                    . ($index === 0 ? ' aria-current="true"' : '')
                    // translators: %d: slide number, starting from 1.
                    . ' aria-label="' . self::escape(sprintf(__('Go to slide %d', 'nucleo'), $index + 1)) . '"></button>';
            }
            $dotsHtml .= '</div>';
        }

        $controls = '';
        if ($arrows || $autoplay > 0) {
            $controls = '<div class="nc-slider__controls">';
            if ($arrows) {
                $controls .= '<button type="button" class="nc-slider__prev" aria-label="'
                    . self::escape(self::text($node, 'prevLabel', __('Previous slide', 'nucleo'))) . '"><span aria-hidden="true">←</span></button>';
            }
            if ($autoplay > 0) {
                // Dal server parte come "Pause carousel"; view.js lo passa a "Play carousel" con prefers-reduced-motion.
                $pauseLabel = self::escape(self::text($node, 'pauseLabel', __('Pause carousel', 'nucleo')));
                $playLabel = self::escape(self::text($node, 'playLabel', __('Play carousel', 'nucleo')));
                $controls .= '<button type="button" class="nc-slider__toggle" aria-label="' . $pauseLabel . '"'
                    . ' data-label-pause="' . $pauseLabel . '" data-label-play="' . $playLabel . '">'
                    . '<span aria-hidden="true">❚❚</span></button>';
            }
            if ($arrows) {
                $controls .= '<button type="button" class="nc-slider__next" aria-label="'
                    . self::escape(self::text($node, 'nextLabel', __('Next slide', 'nucleo'))) . '"><span aria-hidden="true">→</span></button>';
            }
            $controls .= '</div>';
        }

        // Fuori da "below" punti e controlli stanno in un solo contenitore assoluto sopra le slide.
        $overlay = '';
        if ($controlsPosition !== 'below' && ($dotsHtml !== '' || $controls !== '')) {
            $overlay = '<div class="nc-slider__ui nc-slider__ui--' . $controlsPosition . '">'
                . $dotsHtml . $controls
                . '</div>';
        }

        $class = self::escape($ctx->classes($node));

        return '<div class="' . $class . '" role="region" aria-roledescription="carousel"'
            . ' aria-label="' . self::escape($label) . '"'
            . ' data-per-view="' . $perView . '"'
            . ' data-effect="' . $effect . '"'
            . ' data-autoplay="' . $autoplay . '"'
            . ' data-loop="' . ($loop ? 'true' : 'false') . '"'
            . ' data-pause-on-hover="' . ($pauseOnHover ? 'true' : 'false') . '"'
            . ' style="--nc-slider-speed:' . $speed . 'ms">'
            . '<div class="nc-slider__track">' . $slides . '</div>'
            . ($controlsPosition === 'below' ? $dotsHtml . $controls : $overlay)
            . '</div>';
    }

    /**
     * Testo da content, altrimenti il predefinito. Il blocco è statico: il predefinito usa la lingua di chi salva
     * (limite documentato in docs/04-blocchi.md); un testo in content vale in ogni lingua.
     */
    private static function text(Node $node, string $field, string $default): string
    {
        $value = $node->content[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
