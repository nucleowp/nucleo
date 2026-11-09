<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Link senza destinazione (href vuoto, '#' o javascript:) e <a> senza href nei testi: pulsanti mascherati da link. */
final class ButtonAsLink implements Rule
{
    /** Un <a> senza attributo href (il lookahead salta gli <a> che lo hanno). */
    private const ANCHOR_WITHOUT_HREF = '/<a\b(?![^>]*\bhref\s*=)[^>]*>/i';

    public function id(): string
    {
        return 'a11y.button.as-link';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $types = [];
        foreach ($page->nodes as $entry) {
            $types[$entry['doc'] . '|' . $entry['id']] = $entry['type'];
        }

        $issues = [];
        foreach ($page->links as $link) {
            // I nodi nc/button hanno il proprio controllo (content.button.inert).
            if (($types[$link['doc'] . '|' . $link['id']] ?? '') === 'nc/button') {
                continue;
            }

            $href = trim($link['href']);
            if ($href !== '' && $href !== '#' && !str_starts_with(strtolower($href), 'javascript:')) {
                continue;
            }

            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                sprintf(
                    /* translators: 1: link text, 2: href value. */
                    __('The link "%1$s" has no real destination (href "%2$s"): use a button or add an href.', 'nucleo'),
                    $link['text'],
                    $link['href'],
                ),
                [['doc' => $link['doc'], 'id' => $link['id']]],
            );
        }

        foreach ($page->nodes as $entry) {
            if ($entry['type'] !== 'nc/text') {
                continue;
            }

            $content = is_array($entry['node']['content'] ?? null) ? $entry['node']['content'] : [];
            // nc/text tiene il testo rich text in content.html.
            $text = $content['html'] ?? null;
            if (!is_string($text) || preg_match(self::ANCHOR_WITHOUT_HREF, $text) !== 1) {
                continue;
            }

            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                __('The text contains a link (a) without href: it is not reachable as a link.', 'nucleo'),
                [['doc' => $entry['doc'], 'id' => $entry['id']]],
            );
        }

        return $issues;
    }
}
