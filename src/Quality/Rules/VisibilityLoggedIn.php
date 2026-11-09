<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Un contenuto visibile solo a utenti loggati è invisibile ad anonimi e motori di ricerca. */
final class VisibilityLoggedIn implements Rule
{
    public function id(): string
    {
        return 'visibility.logged_in';
    }

    public function severity(): Severity
    {
        return Severity::Info;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->conditional as $entry) {
            $restricted = false;
            foreach ($entry['when'] as $is) {
                if ($is === 'logged_in' || str_starts_with($is, 'role:')) {
                    $restricted = true;
                    break;
                }
            }
            if (!$restricted) {
                continue;
            }
            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                __('content hidden from anonymous visitors and search engines', 'nucleo'),
                [['doc' => $entry['doc'], 'id' => $entry['id']]],
            );
        }

        return $issues;
    }
}
