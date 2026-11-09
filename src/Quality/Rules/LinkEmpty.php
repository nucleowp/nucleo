<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Link o pulsante senza testo accessibile (né testo visibile né aria-label). */
final class LinkEmpty implements Rule
{
    public function id(): string
    {
        return 'a11y.link.empty';
    }

    public function severity(): Severity
    {
        return Severity::Error;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->links as $link) {
            if ($link['text'] !== '' || ($link['ariaLabel'] !== null && trim($link['ariaLabel']) !== '')) {
                continue;
            }
            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                __('Link without accessible text.', 'nucleo'),
                [['doc' => $link['doc'], 'id' => $link['id']]],
            );
        }

        return $issues;
    }
}
