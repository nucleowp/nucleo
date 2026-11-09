<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/**
 * Due o più layout con la stessa area e le stesse condizioni: non è chiaro quale vinca.
 */
final class LayoutConflict implements Rule
{
    public function id(): string
    {
        return 'ds.layout.conflict';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $groups = [];
        foreach ($page->layouts as $layout) {
            $groups[json_encode([$layout['area'], $layout['conditions']])][] = $layout;
        }

        $issues = [];
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                sprintf(/* translators: %s: layout area, e.g. header */ __('More than one layout for the area %s has the same conditions.', 'nucleo'), $group[0]['area']),
                array_map(
                    static fn (array $layout): array => ['doc' => $layout['doc'], 'id' => $layout['id']],
                    $group,
                ),
            );
        }

        return $issues;
    }
}
