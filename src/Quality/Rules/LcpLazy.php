<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** L'immagine LCP stimata non deve avere loading="lazy". */
final class LcpLazy implements Rule
{
    public function id(): string
    {
        return 'perf.lcp.lazy';
    }

    public function severity(): Severity
    {
        return Severity::Error;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->images as $image) {
            if (!$image['lcp'] || !$image['lazy']) {
                continue;
            }
            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                __('The LCP image has loading="lazy".', 'nucleo'),
                [['doc' => $image['doc'], 'id' => $image['id']]],
            );
        }

        return $issues;
    }
}
