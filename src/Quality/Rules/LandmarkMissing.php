<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Nella pagina composta mancano i landmark main, nav o footer. */
final class LandmarkMissing implements Rule
{
    public function id(): string
    {
        return 'a11y.landmark.missing';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $missing = array_values(array_diff(['main', 'nav', 'footer'], $page->landmarks));
        if ($missing === []) {
            return [];
        }

        return [new Issue($this->id(), $this->severity(), sprintf(
            /* translators: %s: missing landmarks, e.g. main, nav. */
            __('The composed page has no landmark: %s.', 'nucleo'),
            implode(', ', $missing),
        ))];
    }
}
