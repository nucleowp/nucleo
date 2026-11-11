<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

defined('ABSPATH') || exit;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Testo o titolo nascosto su mobile ma visibile su desktop (o viceversa): può sparire dall'indicizzazione. */
final class HiddenContent implements Rule
{
    public function id(): string
    {
        return 'seo.hidden.content';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $nodes = [];
        foreach ($page->nodes as $entry) {
            $base = $entry['visible']['base'] ?? true;
            $lg = $entry['visible']['lg'] ?? true;
            if ($base === $lg) {
                continue;
            }

            $text = self::text($entry['node'], $entry['type']);
            if ($text === '') {
                continue;
            }

            $isHeading = $entry['type'] === 'nc/heading';
            $isLongText = $entry['type'] === 'nc/text' && count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []) >= 20;
            if (!$isHeading && !$isLongText) {
                continue;
            }

            $nodes[] = ['doc' => $entry['doc'], 'id' => $entry['id']];
        }

        if ($nodes === []) {
            return [];
        }

        return [new Issue(
            $this->id(),
            $this->severity(),
            __('Headings or text are hidden on some breakpoints and visible on others.', 'nucleo'),
            $nodes,
        )];
    }

    /**
     * Testo senza tag: nc/text sta in content.html (rich text), nc/heading in content.text.
     *
     * @param array<mixed> $node
     */
    private static function text(array $node, string $type): string
    {
        $content = is_array($node['content'] ?? null) ? $node['content'] : [];
        $key = $type === 'nc/text' ? 'html' : 'text';
        $raw = is_string($content[$key] ?? null) ? $content[$key] : '';

        return trim(wp_strip_all_tags($raw));
    }
}
