<?php

declare(strict_types=1);

namespace Nucleo\Quality;

use Nucleo\Integrations\SeoBridge;

/** Controlli SEO del documento: title, meta description, noindex, canonical e link interni, letti dal plugin SEO attivo e dal post. */
final class PostChecks
{
    /** Regole di questa classe con la gravità: la scheda "Regole" della pagina qualità le legge da qui. */
    public const RULES = [
        'seo.title.missing' => 'warning',
        'seo.title.length' => 'warning',
        'seo.h1.title-mismatch' => 'info',
        'seo.description.missing' => 'warning',
        'seo.description.length' => 'info',
        'seo.content.thin' => 'warning',
        'seo.noindex' => 'warning',
        'seo.noindex.important' => 'error',
        'seo.canonical.external' => 'warning',
        'seo.canonical.conflict' => 'warning',
        'seo.links.internal.none' => 'info',
    ];

    /** Soglia di parole della pagina, se non configurata in nucleo_quality. */
    private const THIN_WORDS = 300;

    /**
     * @param ComposedPage|null $page pagina composta del post: serve alle regole che confrontano h1 e title
     * @return list<Issue>
     */
    public static function check(int $postId, ?ComposedPage $page = null): array
    {
        $meta = SeoBridge::meta($postId);
        $nodes = [['doc' => 'page:' . $postId, 'id' => 'meta']];
        $issues = [];

        $titleLength = mb_strlen($meta['title']);
        if ($meta['title'] === '') {
            $issues[] = self::issue(
                'seo.title.missing',
                __('The SEO title is missing.', 'nucleo'),
                $nodes,
            );
        } elseif ($titleLength < 30 || $titleLength > 60) {
            $issues[] = self::issue(
                'seo.title.length',
                sprintf(/* translators: %d: length of the SEO title in characters */ __('The SEO title is %d characters long: 30-60 recommended.', 'nucleo'), $titleLength),
                $nodes,
            );
        }

        if ($page !== null && $meta['title'] !== '') {
            foreach ($page->headings as $heading) {
                if ($heading['level'] !== 1) {
                    continue;
                }
                if (self::differs($heading['text'], $meta['title'])) {
                    $issues[] = self::issue(
                        'seo.h1.title-mismatch',
                        sprintf(/* translators: 1: text of the first h1, 2: SEO title */ __('The first h1 "%1$s" does not match the SEO title "%2$s".', 'nucleo'), $heading['text'], $meta['title']),
                        [['doc' => $heading['doc'], 'id' => $heading['id']]],
                    );
                }
                break;
            }
        }

        if ($meta['description'] === '') {
            $issues[] = self::issue(
                'seo.description.missing',
                __('Meta description missing.', 'nucleo'),
                $nodes,
            );
        } else {
            $descriptionLength = mb_strlen($meta['description']);
            if ($descriptionLength < 70 || $descriptionLength > 160) {
                $issues[] = self::issue(
                    'seo.description.length',
                    sprintf(/* translators: %d: length of the meta description in characters */ __('The meta description is %d characters long: 70-160 recommended.', 'nucleo'), $descriptionLength),
                    $nodes,
                );
            }
        }

        $content = (string) get_post_field('post_content', $postId);

        $threshold = self::thinWords($postId);
        $words = self::countWords($content);
        if ($threshold > 0 && $words < $threshold) {
            $issues[] = self::issue(
                'seo.content.thin',
                sprintf(/* translators: 1: number of words in the page, 2: recommended minimum */ __('The page text is %1$d words long: at least %2$d recommended.', 'nucleo'), $words, $threshold),
                $nodes,
            );
        }

        if ($meta['noindex']) {
            $issues[] = self::inPrimaryMenu($postId)
                ? self::issue(
                    'seo.noindex.important',
                    __('The page is marked noindex but it is in the main menu.', 'nucleo'),
                    $nodes,
                )
                : self::issue(
                    'seo.noindex',
                    __('The page is marked noindex: search engines do not index it.', 'nucleo'),
                    $nodes,
                );
        }

        $homeHost = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

        if ($meta['canonical'] !== '') {
            $canonicalHost = wp_parse_url($meta['canonical'], PHP_URL_HOST);
            if (is_string($canonicalHost)) {
                if (strtolower($canonicalHost) !== $homeHost) {
                    $issues[] = self::issue(
                        'seo.canonical.external',
                        sprintf(/* translators: 1: host of the canonical URL, 2: host of the site */ __('The canonical points to %1$s, which differs from the site %2$s.', 'nucleo'), $canonicalHost, $homeHost),
                        $nodes,
                    );
                } elseif (($canonicalId = url_to_postid($meta['canonical'])) > 0 && $canonicalId !== $postId) {
                    $issues[] = self::issue(
                        'seo.canonical.conflict',
                        __('The canonical points to another page of this site.', 'nucleo'),
                        $nodes,
                    );
                }
            }
        }

        $hrefs = self::hrefs($content);
        $internal = count(array_filter($hrefs, static fn (string $href): bool => self::isInternal($href, $homeHost)));
        if ($internal === 0) {
            $total = count($hrefs);
            $issues[] = self::issue(
                'seo.links.internal.none',
                sprintf(/* translators: %d: number of links found in the content */ __('No internal links in the content: links found are %d.', 'nucleo'), $total),
                $nodes,
            );
        }

        return $issues;
    }

    /**
     * Problema con la gravità dichiarata in self::RULES.
     *
     * @param list<array{doc:string,id:string}> $nodes
     */
    private static function issue(string $rule, string $message, array $nodes): Issue
    {
        return new Issue($rule, Severity::from(self::RULES[$rule]), $message, $nodes);
    }

    /** Soglia di parole da nucleo_quality['thinWords'][tipo di post]; 0 disattiva il controllo. */
    private static function thinWords(int $postId): int
    {
        $quality = get_option('nucleo_quality', []);
        $thin = is_array($quality) && is_array($quality['thinWords'] ?? null) ? $quality['thinWords'] : [];
        $limit = $thin[(string) get_post_type($postId)] ?? null;

        return is_int($limit) ? $limit : self::THIN_WORDS;
    }

    /** Il post è tra le voci del menu nella posizione 'primary' (o la prima registrata). */
    private static function inPrimaryMenu(int $postId): bool
    {
        $registered = get_registered_nav_menus();
        $location = isset($registered['primary']) ? 'primary' : array_key_first($registered);
        if (!is_string($location)) {
            return false;
        }

        $locations = get_nav_menu_locations();
        $menuId = is_array($locations) ? (int) ($locations[$location] ?? 0) : 0;
        if ($menuId <= 0) {
            return false;
        }

        $items = wp_get_nav_menu_items($menuId);
        if (!is_array($items)) {
            return false;
        }
        foreach ($items as $item) {
            if ((int) ($item->object_id ?? 0) === $postId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Titolo e h1 diversi: nessuno dei due contiene l'altro e la somiglianza è sotto il 40%.
     * Confronto in minuscolo, senza punteggiatura.
     */
    private static function differs(string $heading, string $title): bool
    {
        $a = self::normalize($heading);
        $b = self::normalize($title);
        if ($a === '' || $b === '') {
            return false;
        }
        if (str_contains($a, $b) || str_contains($b, $a)) {
            return false;
        }

        similar_text($a, $b, $percent);

        return $percent < 40;
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/[\p{P}\s]+/u', ' ', mb_strtolower($text)));
    }

    /** Conta le parole del testo di $html, senza tag e con le entità decodificate. */
    private static function countWords(string $html): int
    {
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
        $words = preg_split('/[\s\x{00A0}]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? count($words) : 0;
    }

    /** @return list<string> */
    private static function hrefs(string $html): array
    {
        preg_match_all('/<a\s[^>]*?\bhref\s*=\s*(["\'])(.*?)\1/i', $html, $matches);

        return $matches[2];
    }

    /** Link interno: host uguale a quello del sito, oppure href relativo senza schema. */
    private static function isInternal(string $href, string $homeHost): bool
    {
        $host = wp_parse_url($href, PHP_URL_HOST);
        if (is_string($host)) {
            return strtolower($host) === $homeHost;
        }

        return !is_string(wp_parse_url($href, PHP_URL_SCHEME));
    }
}
