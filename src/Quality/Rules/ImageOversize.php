<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/**
 * Immagine sorgente più di due volte più larga di quanto viene mostrata, senza srcset. Un avviso per nodo.
 * La larghezza mostrata è il massimo in px di style.width e style.maxWidth su tutti i breakpoint; rem ed em valgono 16px, le altre unità sono ignote.
 */
final class ImageOversize implements Rule
{
    public function id(): string
    {
        return 'perf.image.oversize';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->nodes as $entry) {
            if ($entry['type'] !== 'nc/image') {
                continue;
            }

            $content = $entry['node']['content'] ?? [];
            $width = $content['width'] ?? null;
            if (!is_int($width) || ($content['srcset'] ?? '') !== '') {
                continue;
            }

            $displayed = $this->displayedWidth($entry['node']['style'] ?? []);
            if ($displayed === null || $displayed <= 0 || $width <= 2 * $displayed) {
                continue;
            }

            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                sprintf(
                    /* translators: 1: node id, 2: source width in px, 3: displayed width in px. */
                    __('Image "%1$s" is %2$dpx wide but is shown at %3$dpx: more than twice the displayed size.', 'nucleo'),
                    $entry['id'],
                    $width,
                    (int) round($displayed),
                ),
                [['doc' => $entry['doc'], 'id' => $entry['id']]],
            );
        }

        return $issues;
    }

    /** Massimo in px di width e maxWidth su tutti i breakpoint; null se nessun valore è misurabile. */
    private function displayedWidth(mixed $style): ?float
    {
        if (!is_array($style)) {
            return null;
        }

        $widths = [];
        foreach (['width', 'maxWidth'] as $property) {
            $value = $style[$property] ?? null;
            foreach (is_array($value) ? $value : [$value] as $item) {
                $px = $this->px($item);
                if ($px !== null) {
                    $widths[] = $px;
                }
            }
        }

        return $widths === [] ? null : max($widths);
    }

    private function px(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value) || preg_match('/^\s*(\d+(?:\.\d+)?)\s*(px|rem|em)?\s*$/i', $value, $m) !== 1) {
            return null;
        }

        $number = (float) $m[1];

        return in_array(strtolower($m[2] ?? 'px'), ['rem', 'em'], true) ? $number * 16 : $number;
    }
}
