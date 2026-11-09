<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Stessa ancora (settings.anchor) su più nodi della pagina composta: l'ancora punta a un solo elemento. */
final class AnchorDuplicate implements Rule
{
    public function id(): string
    {
        return 'a11y.anchor.duplicate';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $byAnchor = [];
        foreach ($page->anchors as $entry) {
            $byAnchor[$entry['anchor']][] = ['doc' => $entry['doc'], 'id' => $entry['id']];
        }

        $issues = [];
        foreach ($byAnchor as $anchor => $nodes) {
            if (count($nodes) < 2) {
                continue;
            }
            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                sprintf(/* translators: %s: the repeated anchor */ __('The anchor "%s" is used by more than one node.', 'nucleo'), (string) $anchor),
                $nodes,
            );
        }

        return $issues;
    }
}
