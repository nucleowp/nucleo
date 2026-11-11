<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

defined('ABSPATH') || exit;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Testo in inglese in un sito con altra lingua, senza settings.lang sul blocco. */
final class LangMissing implements Rule
{
    /** Parole vuote più frequenti dell'inglese: un testo inglese ne è pieno. */
    private const STOPWORDS = [
        'the', 'and', 'of', 'to', 'a', 'in', 'is', 'it', 'you', 'that', 'he', 'was', 'for', 'on',
        'are', 'with', 'as', 'his', 'they', 'be', 'at', 'one', 'have', 'this', 'from', 'or', 'had', 'by', 'not', 'but',
    ];

    private readonly string $locale;

    public function __construct(?string $locale = null)
    {
        $this->locale = $locale ?? (function_exists('get_locale') ? get_locale() : 'en_US');
    }

    public function id(): string
    {
        return 'a11y.lang.missing';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        if (str_starts_with(strtolower($this->locale), 'en')) {
            return [];
        }

        $issues = [];
        foreach ($page->nodes as $entry) {
            if (!in_array($entry['type'], ['nc/text', 'nc/heading'], true)) {
                continue;
            }

            $settings = is_array($entry['node']['settings'] ?? null) ? $entry['node']['settings'] : [];
            $lang = $settings['lang'] ?? null;
            if (is_string($lang) && trim($lang) !== '') {
                continue;
            }

            $content = is_array($entry['node']['content'] ?? null) ? $entry['node']['content'] : [];
            // nc/text tiene il testo in content.html; nc/heading in content.text.
            $text = $content[$entry['type'] === 'nc/text' ? 'html' : 'text'] ?? null;
            if (!is_string($text) || !self::isEnglish($text)) {
                continue;
            }

            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                sprintf(
                    /* translators: %s: site locale, e.g. it_IT. */
                    __('The text looks English but the site locale is %s: set settings.lang on the block.', 'nucleo'),
                    $this->locale,
                ),
                [['doc' => $entry['doc'], 'id' => $entry['id']]],
            );
        }

        return $issues;
    }

    /** Testo (senza markup) di almeno 8 parole, con almeno 3 stopword distinte che sono almeno il 25% delle parole. */
    private static function isEnglish(string $html): bool
    {
        $text = strtolower(trim(wp_strip_all_tags($html)));
        $words = preg_split("/[^\\p{L}']+/u", $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || count($words) < 8) {
            return false;
        }

        $stop = array_filter($words, static fn (string $word): bool => in_array($word, self::STOPWORDS, true));
        if (count(array_unique($stop)) < 3) {
            return false;
        }

        return count($stop) / count($words) >= 0.25;
    }
}
