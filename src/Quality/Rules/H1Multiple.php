<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Più di un h1 nella pagina composta. Il fix tiene il primo h1 di un documento "page:" e porta gli altri a h2. */
final class H1Multiple implements Rule
{
    public function id(): string
    {
        return 'seo.h1.multiple';
    }

    public function severity(): Severity
    {
        return Severity::Error;
    }

    public function check(ComposedPage $page): array
    {
        $h1 = array_values(array_filter(
            $page->headings,
            static fn (array $h): bool => $h['level'] === 1,
        ));
        $count = count($h1);
        if ($count < 2) {
            return [];
        }

        $keep = 0;
        foreach ($h1 as $i => $heading) {
            if (str_starts_with($heading['doc'], 'page:')) {
                $keep = $i;
                break;
            }
        }

        $nodes = [];
        $fix = [];
        $where = [];
        foreach ($h1 as $i => $heading) {
            $nodes[] = ['doc' => $heading['doc'], 'id' => $heading['id']];
            $where[] = $heading['id'] . ' in ' . $heading['doc'];
            if ($i !== $keep) {
                $fix[] = [
                    'op' => 'set',
                    'doc' => $heading['doc'],
                    'id' => $heading['id'],
                    'path' => 'settings.level',
                    'value' => 2,
                ];
            }
        }

        return [new Issue(
            $this->id(),
            $this->severity(),
            sprintf(/* translators: 1: number of h1 headings, 2: list of the h1 references */ __('The composed page contains %1$d h1: %2$s.', 'nucleo'), $count, implode(', ', $where)),
            $nodes,
            $fix,
        )];
    }
}
