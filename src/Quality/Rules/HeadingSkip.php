<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

defined('ABSPATH') || exit;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Salto di livello tra titoli consecutivi (per esempio h2 seguito da h4). */
final class HeadingSkip implements Rule
{
    public function id(): string
    {
        return 'seo.heading.skip';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        $prev = null;
        foreach ($page->headings as $heading) {
            if ($prev !== null && $heading['level'] - $prev['level'] > 1) {
                $issues[] = new Issue(
                    $this->id(),
                    $this->severity(),
                    sprintf(/* translators: 1: previous heading level, 2: next heading level */ __('Heading level skipped: from h%1$d to h%2$d.', 'nucleo'), $prev['level'], $heading['level']),
                    [
                        ['doc' => $prev['doc'], 'id' => $prev['id']],
                        ['doc' => $heading['doc'], 'id' => $heading['id']],
                    ],
                );
            }
            $prev = $heading;
        }

        return $issues;
    }
}
