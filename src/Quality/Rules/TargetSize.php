<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Pulsante o icona più piccoli di 24px nel breakpoint base: bersaglio troppo piccolo da toccare. */
final class TargetSize implements Rule
{
    private const MIN_PX = 24;

    public function id(): string
    {
        return 'a11y.target.size';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->nodes as $entry) {
            if (!in_array($entry['type'], ['nc/button', 'nc/icon'], true)) {
                continue;
            }

            $style = is_array($entry['node']['style'] ?? null) ? $entry['node']['style'] : [];
            if (!self::tooSmall($style['width'] ?? null) && !self::tooSmall($style['height'] ?? null)) {
                continue;
            }

            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                sprintf(
                    /* translators: %s: block name, e.g. nc/button. */
                    __('The %s target is smaller than 24px at the base breakpoint.', 'nucleo'),
                    $entry['type'],
                ),
                [['doc' => $entry['doc'], 'id' => $entry['id']]],
            );
        }

        return $issues;
    }

    /** Dimensione base in px (o rem/em a 16px) sotto la soglia; valori non in unità CSS note non contano. */
    private static function tooSmall(mixed $value): bool
    {
        $base = is_array($value) ? ($value['base'] ?? null) : $value;
        if (!is_string($base) || preg_match('/^(\d+(?:\.\d+)?)\s*(px|rem|em)$/i', trim($base), $m) !== 1) {
            return false;
        }

        $px = strtolower($m[2]) === 'px' ? (float) $m[1] : (float) $m[1] * 16;

        return $px < self::MIN_PX;
    }
}
