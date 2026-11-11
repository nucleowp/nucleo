<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

defined('ABSPATH') || exit;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

final class HeadingEmpty implements Rule
{
    public function id(): string
    {
        return 'seo.heading.empty';
    }

    public function severity(): Severity
    {
        return Severity::Error;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->headings as $heading) {
            if ($heading['text'] !== '') {
                continue;
            }
            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                sprintf(/* translators: %d: heading level, 1 to 6 */ __('Empty heading (h%d).', 'nucleo'), $heading['level']),
                [['doc' => $heading['doc'], 'id' => $heading['id']]],
            );
        }

        return $issues;
    }
}
