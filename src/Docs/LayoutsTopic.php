<?php

declare(strict_types=1);

namespace Nucleo\Docs;

defined('ABSPATH') || exit;

use Nucleo\Layout\LayoutConditions;

/** Topic "layouts" di nucleo/catalog-get: aree, condizioni e scelta del layout (docs/contracts/layout-keys.md). */
final class LayoutsTopic
{
    /** Cosa seleziona ogni tipo di condizione. */
    private const MEANING = [
        'entire_site' => 'every request',
        'front' => 'the front page',
        'blog' => 'the posts page',
        'search' => 'search results',
        '404' => 'the not-found page',
        'post' => 'one post or page, by ID',
        'parent' => 'every descendant page of a page, by ID',
        'post_type' => 'every single item of a post type (value = post type slug, e.g. "prodotto")',
        'archive' => 'the archive of a post type or taxonomy (value = its slug)',
        'author' => 'single items written by an author, by user ID',
        'author_archive' => 'the archive of an author, by user ID',
        'term' => 'single items with a term (taxonomy + term ID; children true adds the child terms)',
        'term_archive' => 'the archive of a term (taxonomy + term ID; children true adds the child terms)',
        'language' => 'limits the layout to a language (value = language code); it adds no match of its own',
    ];

    /** @return array{topic:string,summary:string,rules:list<string>,data:array<string,mixed>} */
    public static function topic(): array
    {
        $types = [];
        foreach (array_keys(LayoutConditions::TYPES) as $type) {
            $type = (string) $type;
            // Senza rilevamento della lingua la condizione language si rifiuta: non si propone agli agenti.
            if ($type === 'language' && !LayoutConditions::detectsLanguage()) {
                continue;
            }
            $types[$type] = ['format' => (string) LayoutConditions::example($type), 'selects' => self::MEANING[$type] ?? ''];
        }

        return [
            'topic' => 'layouts',
            'summary' => 'Layout documents (kind layout'
                . '): meta.area and meta.conditions decide where a layout applies. layout-explain shows which layout a URL gets and why.',
            'rules' => [
                'meta: {"area": "header"|"footer"|"before-content"|"after-content"|"single"|"archive"|"search"|"notfound"'
                    . '|"product"|"product-archive", "conditions": {"include": [...], "exclude"?: [...], "priority"?: 0}}.',
                'A condition is {"type": "<type>", ...keys of the type}. The value always goes in "value" (and "taxonomy" for term types): {"type": "post_type", "value": "prodotto"}, not "postType". Unknown types, unknown keys and missing keys in new or changed conditions are refused with code layout_condition and the expected format (data.types). A layout saved earlier with invalid conditions can still be edited and recompiled; site-describe lists it in warnings. IDs written as numeric strings ("12") are stored as integers.',
                'IDs (post, parent, author, term) are integers in doc-create, doc-patch and layout-conditions-set. In site files (layouts/*.json) write symbols instead: "page:<path>", "post:<slug>", "<post-type>:<slug>", "term:<taxonomy>/<slug>", "author:<login>"; push turns them into IDs.',
                'Choice: the most specific key wins (front, post, term, author, post type, entire site, in this order for a single item); with the same key, the highest priority, then the newest layout. A layout excluded for any key of the request is skipped. Same area, key and priority = conflict, reported by layout-list.',
                'A page always gets the header and footer whose conditions match it; to remove them on one page, add {"type": "post", "value": <ID>} to exclude.',
                'layout-conditions-set replaces include, exclude and priority of a layout and keeps the rest of the document. After a change, check a URL with layout-explain.',
                'Areas product and product-archive exist only with WooCommerce active: product is the single product page, product-archive the Shop and the product categories and tags. On those pages they win over single and archive (search also wins over archive).'
                    . ' layout-explain marks the losing area with superseded and lists in applied what the page prints.',
            ],
            'data' => [
                'areas' => [
                    'header', 'footer', 'before-content', 'after-content', 'single', 'archive', 'search', 'notfound', 'product', 'product-archive',
                ],
                'types' => $types,
            ],
        ];
    }
}
