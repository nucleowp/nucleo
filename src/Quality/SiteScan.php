<?php

declare(strict_types=1);

namespace Nucleo\Quality;

defined('ABSPATH') || exit;

use Nucleo\Integrations\SeoBridge;
use Nucleo\Seo\SchemaRegistry;
use Nucleo\Services;
use Nucleo\Support\Optional;

/**
 * Controllo trasversale dei documenti Nucleo pubblicati.
 * Le regole di sito leggono tutti i documenti (titoli, h1, link interni); il punteggio
 * delle pagine è calcolato solo per il lotto richiesto.
 */
final class SiteScan
{
    /** Breakpoint in ordine di cascata: un breakpoint mancante eredita il precedente. */
    private const BREAKPOINTS = ['base', 'md', 'lg', 'xl'];

    /** Regole di sito con la gravità: la scheda "Regole" le legge da qui. */
    private const SITE_RULES = [
        'seo.title.duplicate' => 'warning',
        'seo.h1.duplicate' => 'warning',
        'links.orphan' => 'warning',
        'seo.hreflang.missing' => 'warning',
        'seo.schema.duplicate' => 'error',
        'seo.duplicate.section' => 'warning',
    ];

    /**
     * @return array{
     *     site: list<array{rule:string,severity:string,message:string,posts:list<int>}>,
     *     pages: list<array{postId:int,score:int,a11y:int,perf:int}>,
     *     next: ?int,
     *     rules: list<array{id:string,severity:string}>,
     *     errorPages: int
     * }
     */
    public static function run(int $page = 1, int $per = 50): array
    {
        $page = max(1, $page);
        $per = min(200, max(1, $per));

        $ids = self::publishedIds();
        $index = self::index($ids);

        $titles = [];
        $h1s = [];
        foreach ($index as $id => $entry) {
            $titles[$id] = [$entry['title']];
            $h1s[$id] = $entry['h1'];
        }

        $site = array_merge(
            self::duplicates('seo.title.duplicate', 'Title SEO', $titles),
            self::duplicates('seo.h1.duplicate', __('H1 text', 'nucleo'), $h1s),
            self::orphans($index),
            self::hreflang($ids),
            self::schemaDuplicate($index),
            self::duplicateSection($index),
        );

        $batch = array_slice($ids, ($page - 1) * $per, $per);
        $pages = [];
        $errorPosts = [];
        foreach ($batch as $id) {
            $checked = self::check($id);
            if ($checked === null) {
                continue;
            }

            $pages[] = [
                'postId' => $id,
                'score' => $checked['score']['seo'],
                'a11y' => $checked['score']['a11y'],
                'perf' => $checked['score']['perf'],
            ];
            if ($checked['error']) {
                $errorPosts[$id] = true;
            }
        }

        foreach ($site as $issue) {
            if ($issue['severity'] === 'error') {
                foreach ($issue['posts'] as $id) {
                    $errorPosts[$id] = true;
                }
            }
        }

        return [
            'site' => $site,
            'pages' => $pages,
            'next' => count($ids) > $page * $per ? $page + 1 : null,
            'rules' => self::ruleList(),
            'errorPages' => count($errorPosts),
        ];
    }

    /** @return list<int> */
    private static function publishedIds(): array
    {
        $ids = get_posts([
            'post_type' => 'any',
            'post_status' => 'publish',
            'meta_key' => '_nucleo_doc',
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        return array_values(array_map('intval', $ids));
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{title:string,h1:list<string>,links:list<int>,breadcrumbs:bool,sectionClash:bool}>
     */
    private static function index(array $ids): array
    {
        $index = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            $html = $post instanceof \WP_Post ? $post->post_content : '';
            $dom = self::dom($html);
            $nodes = self::rawNodes($id);

            $index[$id] = [
                'title' => self::normalize(SeoBridge::meta($id)['title']),
                'h1' => $dom === null ? [] : self::texts($dom, 'h1'),
                'links' => $dom === null ? [] : self::internalLinks($dom),
                'breadcrumbs' => in_array('nc/breadcrumbs', array_column($nodes, 'type'), true),
                'sectionClash' => self::sectionClash($nodes),
            ];
        }

        return $index;
    }

    /**
     * @param array<int, list<string>> $values postId => valori normalizzati
     * @return list<array{rule:string,severity:string,message:string,posts:list<int>}>
     */
    private static function duplicates(string $rule, string $label, array $values): array
    {
        $groups = [];
        foreach ($values as $id => $list) {
            foreach (array_unique($list) as $value) {
                if ($value !== '') {
                    $groups[$value][] = $id;
                }
            }
        }

        $issues = [];
        foreach ($groups as $value => $posts) {
            if (count($posts) < 2) {
                continue;
            }

            $issues[] = [
                'rule' => $rule,
                'severity' => 'warning',
                'message' => sprintf(/* translators: 1: field label, 2: number of pages, 3: duplicated value */ __('%1$s is the same on %2$d pages: "%3$s".', 'nucleo'), $label, count($posts), $value),
                'posts' => $posts,
            ];
        }

        return $issues;
    }

    /**
     * Pagine pubblicate che nessun altro documento linka (la home è esclusa).
     * @param array<int, array{title:string,h1:list<string>,links:list<int>,breadcrumbs:bool,sectionClash:bool}> $index
     * @return list<array{rule:string,severity:string,message:string,posts:list<int>}>
     */
    private static function orphans(array $index): array
    {
        $linked = [];
        foreach ($index as $id => $entry) {
            foreach ($entry['links'] as $target) {
                if ($target !== $id && isset($index[$target])) {
                    $linked[$target] = true;
                }
            }
        }

        $home = self::homeId();
        $issues = [];
        foreach (array_keys($index) as $id) {
            if ($id === $home || isset($linked[$id])) {
                continue;
            }

            $issues[] = [
                'rule' => 'links.orphan',
                'severity' => 'warning',
                'message' => __('Page without internal links from other documents.', 'nucleo'),
                'posts' => [$id],
            ];
        }

        return $issues;
    }

    /**
     * Pagine pubblicate a cui manca almeno una lingua del sito. Con meno di 2 lingue non c'è nulla da confrontare.
     * @param list<int> $ids
     * @return list<array{rule:string,severity:string,message:string,posts:list<int>}>
     */
    private static function hreflang(array $ids): array
    {
        $found = Optional::call('Nucleo\\Integrations\\Multilingual', 'languages');
        $languages = apply_filters('nucleo/languages', is_array($found) ? $found : []);
        if (!is_array($languages) || count($languages) < 2) {
            return [];
        }

        $missing = [];
        foreach ($ids as $id) {
            $list = Optional::call('Nucleo\\Integrations\\Multilingual', 'translations', $id);
            $translations = apply_filters('nucleo/translations', is_array($list) ? $list : [], $id);
            $present = is_array($translations) ? array_map('strval', array_keys($translations)) : [];
            if (array_diff(array_map('strval', $languages), $present) !== []) {
                $missing[] = $id;
            }
        }

        if ($missing === []) {
            return [];
        }

        return [[
            'rule' => 'seo.hreflang.missing',
            'severity' => 'warning',
            // Il numero di pagine è in 'posts': il testo non lo ripete.
            'message' => __('Pages without a translation in all site languages.', 'nucleo'),
            'posts' => $missing,
        ]];
    }

    /**
     * Breadcrumb del blocco e BreadcrumbList del plugin SEO: lo stesso dato due volte. Vale solo se il plugin
     * emette la BreadcrumbList e Nucleo non la esclude.
     * @param array<int, array{title:string,h1:list<string>,links:list<int>,breadcrumbs:bool,sectionClash:bool}> $index
     * @return list<array{rule:string,severity:string,message:string,posts:list<int>}>
     */
    private static function schemaDuplicate(array $index): array
    {
        if (SeoBridge::active() === null || in_array('BreadcrumbList', SchemaRegistry::skipTypes(), true)) {
            return [];
        }

        $posts = [];
        foreach ($index as $id => $entry) {
            if ($entry['breadcrumbs']) {
                $posts[] = $id;
            }
        }

        if ($posts === []) {
            return [];
        }

        return [[
            'rule' => 'seo.schema.duplicate',
            'severity' => self::SITE_RULES['seo.schema.duplicate'],
            'message' => __('The SEO plugin and Nucleo both print a BreadcrumbList on pages with breadcrumbs.', 'nucleo'),
            'posts' => $posts,
        ]];
    }

    /**
     * Pagine in cui una stessa sezione compare due volte: una nascosta su base, l'altra su lg.
     * @param array<int, array{title:string,h1:list<string>,links:list<int>,breadcrumbs:bool,sectionClash:bool}> $index
     * @return list<array{rule:string,severity:string,message:string,posts:list<int>}>
     */
    private static function duplicateSection(array $index): array
    {
        $posts = [];
        foreach ($index as $id => $entry) {
            if ($entry['sectionClash']) {
                $posts[] = $id;
            }
        }

        if ($posts === []) {
            return [];
        }

        return [[
            'rule' => 'seo.duplicate.section',
            'severity' => self::SITE_RULES['seo.duplicate.section'],
            'message' => __('A section is repeated on the page, shown only on small screens and only on large screens: its content is duplicated.', 'nucleo'),
            'posts' => $posts,
        ]];
    }

    /**
     * Regole con la gravità, senza duplicati per id: quelle del motore, di PostChecks e di sito.
     * @return list<array{id:string,severity:string}>
     */
    private static function ruleList(): array
    {
        $severities = [];
        foreach (Services::quality()->rules() as $rule) {
            $severities[$rule['id']] ??= $rule['severity'];
        }
        foreach (PostChecks::RULES as $id => $severity) {
            $severities[$id] ??= $severity;
        }
        foreach (self::SITE_RULES as $id => $severity) {
            $severities[$id] ??= $severity;
        }

        $rules = [];
        foreach ($severities as $id => $severity) {
            $rules[] = ['id' => (string) $id, 'severity' => $severity];
        }

        return $rules;
    }

    /**
     * Punteggi della pagina e vero se ha almeno un problema di gravità error aperto (controllo di qualità e PostChecks).
     * @return array{score:array{seo:int,a11y:int,perf:int},error:bool}|null
     */
    private static function check(int $id): ?array
    {
        $composed = self::compose($id);
        if ($composed === null) {
            return null;
        }

        $report = Services::quality()->check($composed)->withoutLayoutIssues();
        $withSeo = new QualityReport(
            array_merge($report->issues, PostChecks::check($id, $composed)),
            $report->acknowledged,
        );

        $error = false;
        foreach ($withSeo->issues as $issue) {
            if ($issue->severity === Severity::Error) {
                $error = true;
                break;
            }
        }

        return [
            'score' => $withSeo->toArray()['quality']['score'],
            'error' => $error,
        ];
    }

    /** Pagina composta del documento del post, o null se il post non ha documento. */
    private static function compose(int $id): ?ComposedPage
    {
        $found = Services::documents()->get($id);
        if ($found === null) {
            return null;
        }

        $doc = Services::documents()->prepare($found['document'], $id)->doc;

        return Services::composer()->composeForPost($id, 'page:' . $id, $doc);
    }

    /**
     * Nodi del documento salvato del post, in ordine di documento, con i breakpoint in cui ciascuno è nascosto
     * (anche per effetto di un antenato). Niente composizione: si legge l'albero grezzo.
     * @return list<array{type:string,section:?string,hidden:array<string,bool>}>
     */
    private static function rawNodes(int $id): array
    {
        $found = Services::documents()->get($id);
        $root = $found['document']['root'] ?? null;

        $nodes = [];
        if (is_array($root)) {
            self::collect($root, [], $nodes);
        }

        return $nodes;
    }

    /**
     * @param array<mixed> $node nodo grezzo del documento
     * @param array<string,bool> $hiddenAbove breakpoint nascosti dall'antenato
     * @param list<array{type:string,section:?string,hidden:array<string,bool>}> $nodes
     */
    private static function collect(array $node, array $hiddenAbove, array &$nodes): void
    {
        $own = self::hiddenMap($node['visibility']['breakpoints'] ?? null);
        $hidden = [];
        foreach (self::BREAKPOINTS as $bp) {
            $hidden[$bp] = ($hiddenAbove[$bp] ?? false) || $own[$bp];
        }

        if (is_string($node['type'] ?? null)) {
            $section = $node['settings']['section'] ?? null;
            $nodes[] = [
                'type' => $node['type'],
                'section' => is_string($section) ? $section : null,
                'hidden' => $hidden,
            ];
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                self::collect($child, $hidden, $nodes);
            }
        }
    }

    /**
     * Breakpoint nascosti da visibility.breakpoints: false nasconde; un breakpoint mancante eredita il precedente.
     * @return array<string,bool>
     */
    private static function hiddenMap(mixed $breakpoints): array
    {
        $hidden = [];
        $previous = false;
        foreach (self::BREAKPOINTS as $bp) {
            $value = is_array($breakpoints) ? ($breakpoints[$bp] ?? null) : null;
            if (is_bool($value)) {
                $previous = $value === false;
            }
            $hidden[$bp] = $previous;
        }

        return $hidden;
    }

    /**
     * Vero se due nc/section-ref con la stessa sezione sono nascosti uno su base e l'altro su lg.
     * Un solo nodo nascosto su entrambi i breakpoint non basta.
     * @param list<array{type:string,section:?string,hidden:array<string,bool>}> $nodes
     */
    private static function sectionClash(array $nodes): bool
    {
        $hiddenBase = [];
        $hiddenLg = [];
        foreach ($nodes as $i => $node) {
            if ($node['type'] !== 'nc/section-ref' || $node['section'] === null) {
                continue;
            }

            if ($node['hidden']['base']) {
                $hiddenBase[$node['section']][] = $i;
            }
            if ($node['hidden']['lg']) {
                $hiddenLg[$node['section']][] = $i;
            }
        }

        foreach ($hiddenBase as $section => $baseIdx) {
            foreach ($hiddenLg[$section] ?? [] as $lgIdx) {
                foreach ($baseIdx as $idx) {
                    if ($idx !== $lgIdx) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function homeId(): int
    {
        return get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0;
    }

    private static function dom(string $html): ?\DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<meta charset="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /** @return list<string> */
    private static function texts(\DOMDocument $dom, string $tag): array
    {
        $texts = [];
        foreach ($dom->getElementsByTagName($tag) as $element) {
            $text = self::normalize($element->textContent);
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /** @return list<int> id dei post interni linkati, senza duplicati */
    private static function internalLinks(\DOMDocument $dom): array
    {
        $targets = [];
        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $id = self::resolve($anchor->getAttribute('href'));
            if ($id > 0) {
                $targets[$id] = $id;
            }
        }

        return array_values($targets);
    }

    private static function resolve(string $href): int
    {
        $href = explode('#', trim($href), 2)[0];
        if ($href === '' || str_starts_with($href, '//')) {
            return 0;
        }

        if (str_starts_with($href, '/')) {
            $href = home_url($href);
        }

        if (!str_starts_with($href, home_url('/'))) {
            return 0;
        }

        return url_to_postid($href);
    }

    private static function normalize(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    }
}
