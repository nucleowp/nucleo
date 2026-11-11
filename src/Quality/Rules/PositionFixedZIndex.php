<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

defined('ABSPATH') || exit;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Un nodo con position fixed senza zIndex può finire sotto il contenuto che scorre. */
final class PositionFixedZIndex implements Rule
{
    public function id(): string
    {
        return 'layout.fixed.z_index';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->fixed as $entry) {
            if ($entry['zIndex']) {
                continue;
            }
            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                __('position fixed without zIndex: the element can end up under the page content; add style.zIndex', 'nucleo'),
                [['doc' => $entry['doc'], 'id' => $entry['id']]],
            );
        }

        return $issues;
    }
}
