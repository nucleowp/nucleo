<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

final class H1Missing implements Rule
{
    public function id(): string
    {
        return 'seo.h1.missing';
    }

    public function severity(): Severity
    {
        return Severity::Error;
    }

    public function check(ComposedPage $page): array
    {
        foreach ($page->headings as $heading) {
            if ($heading['level'] === 1) {
                return [];
            }
        }

        return [new Issue($this->id(), $this->severity(), __('The composed page has no h1.', 'nucleo'))];
    }
}
