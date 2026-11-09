<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Testo grande senza heading (titolo finto) o heading troppo lungo per essere un titolo. */
final class HeadingStyleOnly implements Rule
{
    public function id(): string
    {
        return 'seo.heading.style-only';
    }

    public function severity(): Severity
    {
        return Severity::Info;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->nodes as $entry) {
            $text = self::text($entry['node'], $entry['type']);
            if ($entry['type'] === 'nc/text') {
                // Testo semplice (tag inline tolti), breve e in grande: si comporta come un titolo.
                if ($text === '' || mb_strlen($text) > 120) {
                    continue;
                }
                if (!self::isLargeStyle($entry['node'])) {
                    continue;
                }
            } elseif ($entry['type'] === 'nc/heading') {
                if (mb_strlen($text) <= 150) {
                    continue;
                }
            } else {
                continue;
            }

            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                __('Text styled as a heading, or heading too long to be a title.', 'nucleo'),
                [['doc' => $entry['doc'], 'id' => $entry['id']]],
            );
        }

        return $issues;
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

    /**
     * Fontsize base grande: token con ultimo segmento tipo 6xl/xxl/h1/display/huge, oppure
     * valore letterale da 24px o 1.5rem/em in su.
     *
     * @param array<mixed> $node
     */
    private static function isLargeStyle(array $node): bool
    {
        $style = is_array($node['style'] ?? null) ? $node['style'] : [];
        $fontSize = $style['fontSize'] ?? null;
        $base = is_string($fontSize) ? $fontSize : (is_array($fontSize) && is_string($fontSize['base'] ?? null) ? $fontSize['base'] : null);
        if ($base === null) {
            return false;
        }

        if (str_starts_with($base, '$')) {
            $parts = explode('.', $base);
            $last = (string) end($parts);

            return preg_match('/^(\d*xl|xxl|h[1-3]|display|huge)$/i', $last) === 1;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)(px|rem|em)$/i', trim($base), $m) !== 1) {
            return false;
        }
        $size = (float) $m[1];

        return strtolower($m[2]) === 'px' ? $size >= 24 : $size >= 1.5;
    }
}
