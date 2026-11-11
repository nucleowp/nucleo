<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

defined('ABSPATH') || exit;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Link senza aria-label il cui testo è generico ("clicca qui", "leggi di più"...). */
final class LinkTextGeneric implements Rule
{
    private const GENERIC = [
        // Le vocali accentate sono scritte come escape: il test sulla lingua dei sorgenti le segnalerebbe.
        'clicca qui', 'qui', "leggi di pi\u{f9}", 'leggi tutto', "scopri di pi\u{f9}", 'altro',
        'click here', 'here', 'read more', 'learn more', 'more', 'link',
    ];

    public function id(): string
    {
        return 'seo.link.text.generic';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->links as $link) {
            if ($link['ariaLabel'] !== null && trim($link['ariaLabel']) !== '') {
                continue;
            }
            // Minuscole, spazi ai bordi e punteggiatura finale tolti prima del confronto.
            $text = trim((string) preg_replace('/[\p{P}\s]+$/u', '', mb_strtolower(trim($link['text']))));
            if (!in_array($text, self::GENERIC, true)) {
                continue;
            }
            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                __('Link text is generic: describe where the link goes.', 'nucleo'),
                [['doc' => $link['doc'], 'id' => $link['id']]],
            );
        }

        return $issues;
    }
}
