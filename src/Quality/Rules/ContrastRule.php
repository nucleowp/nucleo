<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

defined('ABSPATH') || exit;

use Nucleo\Quality\Contrast;
use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/**
 * Contrasto testo/sfondo sotto 4.5:1, o 3:1 per il testo grande (titoli di livello 1-2).
 * Le icone con etichetta seguono la soglia 3:1 degli elementi grafici (WCAG 1.4.11);
 * le icone decorative (aria-hidden) non entrano nel controllo.
 * Ogni breakpoint è controllato a parte: il messaggio indica quello in difetto.
 */
final class ContrastRule implements Rule
{
    public function id(): string
    {
        return 'a11y.contrast';
    }

    public function severity(): Severity
    {
        return Severity::Error;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->colorPairs as $pair) {
            $ratio = Contrast::ratio($pair['fg'], $pair['bg']);
            if ($ratio === null) {
                continue;
            }
            $graphic = $pair['graphic'];
            $required = $pair['large'] || $graphic ? 3.0 : 4.5;
            if ($ratio >= $required) {
                continue;
            }
            $where = $pair['breakpoint'] === 'base'
                ? sprintf(/* translators: 1: text or icon color, 2: background color */ __('%1$s on %2$s', 'nucleo'), $pair['fg'], $pair['bg'])
                : sprintf(/* translators: 1: text or icon color, 2: background color, 3: breakpoint name */ __('%1$s on %2$s at breakpoint %3$s', 'nucleo'), $pair['fg'], $pair['bg'], $pair['breakpoint']);
            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                $graphic
                    /* translators: 1: colors, 2: measured contrast ratio, 3: minimum ratio */
                    ? sprintf(__('Icon contrast %1$s is %2$.2f:1, minimum %3$.1f:1 for graphic elements.', 'nucleo'), $where, $ratio, $required)
                    /* translators: 1: colors, 2: measured contrast ratio, 3: minimum ratio */
                    : sprintf(__('Contrast %1$s is %2$.2f:1, minimum %3$.1f:1.', 'nucleo'), $where, $ratio, $required),
                [['doc' => $pair['doc'], 'id' => $pair['id']]],
            );
        }

        return $issues;
    }
}
