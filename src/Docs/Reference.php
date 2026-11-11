<?php

declare(strict_types=1);

namespace Nucleo\Docs;

defined('ABSPATH') || exit;

use Nucleo\Css\Breakpoints;
use Nucleo\Css\Motion;
use Nucleo\Css\PropertyMap;
use Nucleo\Sections\ParamTypes;
use Nucleo\Tokens\TokenSet;

/**
 * Argomenti di nucleo/catalog-get (topic): le regole che un agente deve conoscere senza leggere il codice.
 * Le parti che dipendono dal codice (token, proprietà di stile, breakpoint) si leggono dalle classi, non si copiano.
 * Testi in inglese, come le descrizioni delle ability.
 */
final class Reference
{
    public const TOPICS = [
        'documents', 'tokens', 'styles', 'bindings', 'visibility', 'data', 'theme', 'site-files', 'acknowledge', 'components', 'model', 'layouts',
    ];

    /**
     * @param array<string,array<int|string,mixed>> $tokens token correnti del sito (TokenSet::toArray)
     * @param string $themeCss CSS di base del tema attivo, vuoto se il tema non è nucleo-canvas
     * @param array<string,list<string>> $blockGroups blocco → gruppi di supports.style (topic styles)
     * @return array{topic:string,summary:string,rules:list<string>,data:array<string,mixed>}|null
     */
    public static function topic(string $name, array $tokens = [], string $themeCss = '', array $blockGroups = []): ?array
    {
        return match ($name) {
            'documents' => self::documents(),
            'tokens' => self::tokens($tokens),
            'styles' => self::styles($blockGroups),
            'bindings' => self::bindings(),
            'visibility' => self::visibility(),
            'data' => self::data(),
            'theme' => self::theme($themeCss),
            'site-files' => self::siteFiles(),
            'acknowledge' => self::acknowledge(),
            'components' => self::components(),
            'model' => ModelTopic::topic(),
            'layouts' => LayoutsTopic::topic(),
            default => null,
        };
    }

    /**
     * Chiavi di stile dei gruppi dati, con proprietà CSS, selettore e valori ammessi.
     *
     * @param list<string> $groups gruppi di supports.style del blocco; vuoto = tutti
     * @return array<string,list<array{key:string,property:string,selector?:string,values?:list<string>,number:string}>>
     */
    public static function styleKeys(array $groups = []): array
    {
        $out = [];
        foreach (PropertyMap::keysByGroup() as $group => $keys) {
            if ($groups !== [] && !in_array($group, $groups, true)) {
                continue;
            }
            foreach ($keys as $key) {
                $out[$group][] = self::styleKey($group, $key);
            }
        }

        return $out;
    }

    /** @return array{key:string,property:string,selector?:string,values?:list<string>,number:string} */
    private static function styleKey(string $group, string $key): array
    {
        $motion = $group === 'motion';
        $values = $motion ? Motion::allowed($key) : PropertyMap::allowed($key);

        // Proprietà CSS: la prima dichiarazione prodotta da un valore di prova accettato.
        $property = '';
        foreach ([$values[0] ?? null, 2, '1s', 'opacity 200ms', 'grayscale(1)'] as $sample) {
            if ($sample === null) {
                continue;
            }
            $declaration = PropertyMap::declarations($key, $sample)[0] ?? '';
            if ($declaration !== '') {
                $property = (string) strstr($declaration, ':', true);
                break;
            }
        }

        $entry = ['key' => $key, 'property' => $property];
        $selector = PropertyMap::selector($key);
        if ($selector !== '') {
            $entry['selector'] = trim($selector);
        }
        if ($values !== []) {
            $entry['values'] = array_values(array_map('strval', $values));
        }

        $accepts = $motion ? Motion::accepts($key, 2) : PropertyMap::accepts($key, 2);
        $number = $accepts ? (PropertyMap::declarations($key, 2)[0] ?? '') : '';
        $entry['number'] = match (true) {
            $key === 'columns' => 'N becomes repeat(N,minmax(0,1fr)); a string is used as written',
            $key === 'resultsColumns' => 'N (1-12) becomes repeat(N,minmax(0,1fr)); per breakpoint {"base": 2, "lg": 4}',
            $number === '' => 'numbers not accepted',
            str_ends_with($number, ':2px') => 'a number gets px',
            str_ends_with($number, 'ms') => 'a number is in ms',
            default => 'a number is used without unit',
        };

        return $entry;
    }

    /**
     * @param array<string,array<int|string,mixed>> $tokens
     * @return array{topic:string,summary:string,rules:list<string>,data:array<string,mixed>}
     */
    private static function tokens(array $tokens): array
    {
        $defaults = TokenSet::defaults()->toArray();
        $keys = [];
        foreach ($defaults as $group => $values) {
            $keys[$group] = array_map('strval', array_keys($values));
        }

        return [
            'topic' => 'tokens',
            'summary' => 'Design tokens: fixed groups, default keys plus keys added by the site. A node value "$group.key" becomes var(--nc-group-key).',
            'rules' => [
                'Groups are fixed (data.keys lists the default keys of each group). A new key extends a scale: tokens-set {"space": {"20": 6}} creates $space.20 (keys: lowercase letters, digits and -, at most 48 per group). An unknown group returns invalid_tokens; a $group.key that does not exist is rejected in documents. data.current has the keys of the site.',
                'Formats: color = CSS color (#hex, rgb()/rgba(), hsl(), oklch(), var(--nc-*), transparent, currentColor, basic names); space = number of rem >= 0 (16px = 1rem); text = [minRem, maxRem] pair, fluid with clamp() between 360 and 1920 px of viewport (equal values = fixed size); font = font stack; radius and shadow = CSS text with the same rules as node style values (no url(), image-set(), comments, unbalanced brackets or quotes); container = integer of px > 0.',
                'CSS variables: --nc-<group>-<key> in :root, for example --nc-space-4, --nc-text-xl, --nc-container-lg. A $token in a node value is replaced by var(); a $name that is not a token is not replaced and produces invalid CSS: check data.keys.',
                'tokens-set merges the values sent with the saved tokens: send only the keys to change. null removes a key (back to the default, or gone if the key is new). site.json "tokens" in push is the whole set: it replaces the saved tokens (merged on the defaults), and only when it differs from tokensBase.',
                'Commas are allowed in tokens and in node style values (two shadows, rgba(), minmax(0, 1fr)).',
                'Containers: nc/section inner width is container.lg; theme.json contentSize = container.lg, wideSize = container.xl.',
            ],
            'data' => [
                'keys' => $keys,
                'defaults' => $defaults,
                'current' => $tokens,
            ],
        ];
    }

    /** @return array{topic:string,summary:string,rules:list<string>,data:array<string,mixed>} */
    private static function documents(): array
    {
        return [
            'topic' => 'documents',
            'summary' => 'Document format: {"schema": 1, "kind", "root", "meta"?, "params"?} with a tree of nodes. doc-validate checks a document without saving it.',
            'rules' => [
                'kind: page, layout (header, footer, templates) or section (reusable section with params). '
                    . 'root is one node, usually nc/section for a page.',
                'Node: {"id", "type", "content"?, "settings"?, "style"?, "bind"?, "visibility"?, "children"?, "guide"?}. guide {"distinct": "<reason>"} marks a block that looks like another one but differs on purpose: nucleo guide no longer counts it as a repeated block (R1.5). The schema also accepts the legacy "ref" ("section:<id>", without children): use nc/section-ref instead. Any other key is rejected. At document level, "blocks" ({"<block>": version}) records block versions and is written by Nucleo.',
                'id: ^[a-z0-9]{1,12}$ (lowercase letters and digits, no dash or underscore), unique in the document. Keep ids stable: patches, acknowledgements and translations point to them.',
                'type: a registered block (catalog-list). content holds texts (translatable), settings the options, style the CSS keys of the groups of the block (topic styles). Keys that the manifest does not declare are rejected.',
                'children: array of nodes, only when the manifest says children true (any block) or lists the allowed types. children false: no children. parents lists where a block may stand (nc/collection-card only inside nc/collection).',
                'Links (settings.href, link params): http(s), mailto:, tel:, relative URL, or a reference that follows renames: home, page:<slug>, post:<slug>, <post-type>:<slug>, term:<taxonomy>/<slug>.',
                'Reusable section (kind section): params {"<name>": {"type", "default"?, "required"?, "label"?}}, name ^[a-z][a-z0-9_]{0,31}$. Types: text (string), html (string), link (string, as links above), color (a token "$color.<key>", no literal color), boolean, image ({"src", "alt"?}).',
                'Parameters reach the nodes in two ways. bind {"<content|settings|style>.<key>": {"source": "param", "field": "<name>"}} for any type (image field "<name>.alt" reads the alt; "visible" with a boolean removes the node). In style, a color parameter can also be the whole value: "background": "$param.<name>", also per breakpoint. Other types in style are rejected.',
                'nc/section-ref inserts a section: settings {"section": "<slug>", "params": {"<name>": value}}. Ids inside the section are renamed per instance; errors inside point to the nc/section-ref node. The quality checks of the page read the sections expanded with the params of each instance: an h1, an image alt or a color inside a section counts for the page. push --dry-run expands the sections of the same push too.',
                'bind with other sources reads post, term, author, site or model fields (topic bindings). visibility hides by breakpoint or condition (topic visibility).',
            ],
            'data' => [
                'kinds' => [
                    'page', 'layout', 'section',
                ],
                'nodeKeys' => ['id', 'type', 'content', 'settings', 'style', 'bind', 'visibility', 'children', 'guide'],
                'idPattern' => '^[a-z0-9]{1,12}$',
                'paramTypes' => ParamTypes::TYPES,
                'paramNamePattern' => '^[a-z][a-z0-9_]{0,31}$',
                'example' => [
                    'schema' => 1,
                    'kind' => 'section',
                    'params' => [
                        'title' => ['type' => 'text', 'default' => 'Our lines'],
                        'band' => ['type' => 'color', 'default' => '$color.surface'],
                    ],
                    'root' => [
                        'id' => 'band',
                        'type' => 'nc/section',
                        'style' => ['background' => '$param.band', 'padding' => '$space.8 0'],
                        'children' => [[
                            'id' => 'title',
                            'type' => 'nc/heading',
                            'content' => ['text' => 'Our lines'],
                            'settings' => ['level' => 2],
                            'bind' => ['content.text' => ['source' => 'param', 'field' => 'title']],
                        ]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string,list<string>> $blockGroups
     * @return array{topic:string,summary:string,rules:list<string>,data:array<string,mixed>}
     */
    private static function styles(array $blockGroups = []): array
    {
        return [
            'topic' => 'styles',
            'summary' => 'Node style: keys grouped as in supports.style of each block. catalog-get <block> lists the keys of its groups.',
            'rules' => [
                'A key is accepted only if its group is in the supports.style of the block. Otherwise: "style group X not supported by the block". To style a block without that group, wrap it in nc/container.',
                'Value: a string, a number or an object by breakpoint {base, md, lg, xl} (mobile first, min-width in data.breakpoints).',
                'Numbers: the field number of each key says how a number is written ("a number gets px", "a number is used without unit", "a number is in ms", "numbers not accepted"). values lists the accepted values or their form.',
                'Forbidden in string values: ; { } < > backslash, url(, image-set(, expression(, @import, /* */, unbalanced brackets or quotes and control characters. Commas are allowed (rgba(0, 0, 0, 0.4), minmax(0, 1fr), two shadows).',
                'Values equal to a token must use the token ($space.4): push reports the literal as an error.',
                'Keys with a selector style an inner element of the block (for example the links of nc/menu).',
                'style.parts.<part>: styles an inner element declared in the parts of the block manifest (catalog-get name). Inside a part use the normal style keys of the groups size, spacing, typography, color, border, effects, layout and motion, with breakpoints and states: parts {"label": {"fontWeight": 600}, "placeholder": {"color": "$color.muted"}, "check": {"width": "22px", "accentColor": "$color.primary"}}.',
                'style.custom: last resort for what no key covers. A list of up to 20 rules; Nucleo builds the selector from closed lists: when (state of the node), target (an HTML element or a .nc- class inside the node), child (true: direct child), nth (first, last, odd, even, a number or an+b), state (state of the target), pseudo (before, after, marker, placeholder, first-letter, first-line, selection, backdrop); css: standard CSS property or --variable → value. Example: custom [{"target": "legend", "pseudo": "before", "css": {"content": "counter(g, upper-alpha)"}}]. States: ' . implode(', ', array_keys(\Nucleo\Css\CustomRules::STATES)) . '. Tokens work; url(), { } ; and @ do not. Report each use with nucleo-gap-report.',
                'State keys: <key>Hover styles the node on mouse hover and on keyboard focus inside it; <key>Current styles it while it has the class is-current (the entry of the visible section in nc/toc). Keys: color, background, border and its sides, boxShadow, opacity, filter, fontWeight, textDecoration. Same group and values as the base key. Example, logo grey then colored on hover: filter "grayscale(1)", filterHover "grayscale(0)", transition "filter 200ms".',
                'data.blocks: block → accepted style groups. data.groups: group → keys. A key works on a block only if its group is in the list of the block.',
                'In a reusable section a color parameter can be a whole style value: "background": "$param.<name>" (topic documents).',
                'Breakpoint objects work for the columns key of a layout too: {"base": 1, "md": 2, "lg": 4}. The same form works for resultsColumns of nc/collection.',
            ],
            'data' => [
                'breakpoints' => Breakpoints::DEFAULTS,
                'blocks' => $blockGroups,
                'groups' => self::styleKeys(),
            ],
        ];
    }

    /** @return array{topic:string,summary:string,rules:list<string>,data:array<string,mixed>} */
    private static function bindings(): array
    {
        return [
            'topic' => 'bindings',
            'summary' => 'bind: {"content.<key>"|"settings.<key>": {"source", "field", "fallback"?}} replaces a content or settings value with data. Inside a reusable section, "style.<key>" and "visible" are also accepted with source param.',
            'rules' => [
                'Empty value: the fallback is used; without fallback the value is an empty string and the node still renders (empty text, link without href). To hide the node, add a visibility condition {"is": "filled", "source", "field"} (topic visibility).',
                'Pages: binds that read the page itself (post, nucleo, acf, metabox, jetengine, author, site) are resolved when the page is saved, and the page is compiled again when a watched meta changes. Layouts, sections in layouts and cards: binds are read on every request from the current item (card) or the current object of the request.',
                'Escape: keys ending in src, url, href or poster are URLs (esc_url); content.html is HTML filtered with wp_kses_post; every other key is plain text (esc_html).',
                'source nucleo, field "<key>": a field of the content model of the current post type. image = URL of the attachment; select = labels of the chosen options, comma separated; relation = title of the first related item; boolean = "1" or empty; repeater = no text (see repeaters).',
                'Repeaters: "<key>.<index>.<subfield>" (index from 0) reads one row. For a variable number of rows use nc/repeater (topic data): its child repeats for each row and reads the row with source item.',
                'Cards and repeater rows are cached and shared by every visitor: bind sources user and query are not allowed there.',
                'source item: inside an nc/repeater row (subfields of the row, plus index from 1) or a card of a data source component (fields of the item).',
                'source post: title, excerpt, date, url, image (featured image URL), meta:<key> (raw post meta, scalar only). source author: name, bio, url. source site: name, description, url. source term: name, description, url, count, slug and the fields of the model on the taxonomy; in a card of nc/collection with source terms it reads the term of the card, elsewhere the term of the archive (topic data). source user: name (visitor logged in).',
                'source param: only inside a reusable section, reads a parameter of the nc/section-ref. bind "visible" with a param of type boolean removes the node when false.',
                'style.<key> works only with source param (inside a reusable section): "style.background": {"source": "param", "field": "band"} sets the style key to the parameter value; with any other source the key is rejected. For a color parameter "background": "$param.band" in style does the same (topic documents). The value passes the same checks as any style value.',
                'Arrays, objects, false and null are not values: they render as empty.',
            ],
            'data' => [
                'sources' => ['post', 'nucleo', 'item', 'author', 'site', 'term', 'user', 'acf', 'metabox', 'jetengine', 'param'],
            ],
        ];
    }

    /** @return array{topic:string,summary:string,rules:list<string>,data:array<string,mixed>} */
    private static function visibility(): array
    {
        return [
            'topic' => 'visibility',
            'summary' => 'visibility: {"breakpoints": {...}, "when": [...]} hides a node by screen width, visitor or field value.',
            'rules' => [
                'breakpoints: {"base": false, "lg": true} hides the node from base up to lg (CSS, the HTML stays in the page).',
                'when: list of conditions, all must hold (AND). The node is printed on the server only if they hold.',
                'Visitor conditions: {"is": "logged_in"}, {"is": "logged_out"}, {"is": "role:<slug>"}. Not allowed in cards (nc/query, nc/collection) and repeater rows.',
                'Field conditions: {"is": "filled"|"empty", "source": "<source>", "field": "<field>"}. Sources: post, nucleo, item, acf, metabox, jetengine, author, site, term (term reads the archive term of the request). Fields as in bind (topic bindings). filled = the value is not empty; for a repeater (source nucleo, field = repeater key) filled = at least one row. Allowed everywhere, also in cards and repeater rows (source item).',
                'Examples: hide the PDF button when there is no file: {"is": "filled", "source": "nucleo", "field": "pdf"}. Hide a block of the composition when the repeater is empty: {"is": "filled", "source": "nucleo", "field": "composizione"}.',
                'A reusable section can also remove a node with bind "visible" and a boolean parameter.',
            ],
            'data' => [
                'conditions' => ['logged_in', 'logged_out', 'role:<slug>', 'filled', 'empty'],
            ],
        ];
    }

    /** @return array{topic:string,summary:string,rules:list<string>,data:array<string,mixed>} */
    private static function data(): array
    {
        return [
            'topic' => 'data',
            'summary' => 'Dynamic data in templates: repeaters, related posts, lists of terms, card grids, breadcrumbs.',
            'rules' => [
                'nc/repeater, settings.field = repeater key of the content model: the only child is the row, repeated for each row of the current post (the post of the card inside nc/query or nc/collection). In the row: bind {"source": "item", "field": "<subfield>"} and "index" (from 1; a subfield named index wins). Image subfields are URLs, relations the title of a published item. tag div|ul|ol; empty = text with no rows ("" prints nothing). The row follows the card rules; nc/repeater inside a row is not allowed.',
                'nc/query, settings.relatedTo = taxonomy: published posts that share at least one term of that taxonomy with the current post, without the current post and without password-protected posts ("from the same line"). Type: the type of the current post when postType is "post" (the default) or a type without that taxonomy; otherwise settings.postType. Read on every request and cached until content changes. empty "" prints nothing when there are no results.',
                'nc/post-meta, fields "tax:<taxonomy>": linked terms of the current post, only for public taxonomies. settings.terms "chips" shows them as chips (token colors); "text" as a comma list.',
                'nc/breadcrumbs: homeLabel, archive (post type archive after Home; skipped when it is the home URL), parent {label, href} (a page, for example page:products, instead of the archive), taxonomy (a public taxonomy: the first term of the post in name order, with its parents; there is no primary term). Order: Home, parent or archive, terms, parent posts, current.',
                'nc/collection, settings.source "terms": the terms of a public taxonomy as cards, without PHP (product lines, categories, areas). settings.taxonomy = slug of the taxonomy. parent: "" all levels (default), "0" top level only, "<slug>" the direct children of that term. hideEmpty: true (default) hides terms without published posts of their own (a parent whose posts are all in its children is hidden too). parent accepts only "", "0" or an existing slug: any other value is rejected. orderby: name (default), slug, count or field:<key> (number, date, text or select field of the model on the taxonomy; terms without that field are left out); order asc (default) or desc. perPage and paging as for posts; filters and sorts are not allowed.',
                'Card of a term (source terms): bind {"source": "term", "field": ...} reads the term of the card: name, description, url (link to the term archive), count (published posts), slug, or the key of a field of the model on the taxonomy (image = URL of the attachment). Example: nc/image with bind content.src {"source": "term", "field": "image"}, nc/button with bind settings.href {"source": "term", "field": "url"}. Post blocks (nc/post-title, nc/post-image, nc/post-meta, nc/repeater) and the sources post, nucleo, item, author, acf, metabox, jetengine are not allowed in a term card.',
                'Grid of nc/collection: style.resultsColumns (group results) fixes the number of columns, also per breakpoint: {"base": 2, "lg": 4} = 2 columns on phones, 4 from lg. resultsGap sets the space between cards. Without resultsColumns the columns fit the width (minimum 16rem: 1 column on phones); settings.cardSize "small" lowers the minimum to 9rem (2 columns on phones) without fixing the number.',
                'Visibility by field value: topic visibility.',
                'Data that no native block reads: a data source component with nc/collection (topic components).',
                'Email of nc/native-form: "to" = admin (default), an email address, or field:<field>; "replyTo" = field:<field>; settings.redirect = page shown after sending.',
            ],
            'data' => [],
        ];
    }

    /** @return array{topic:string,summary:string,rules:list<string>,data:array<string,mixed>} */
    private static function theme(string $themeCss): array
    {
        return [
            'topic' => 'theme',
            'summary' => 'Base HTML and CSS around the documents. Block markup and block CSS: catalog-get <block>, fields markup and css.',
            'rules' => [
                'Page shell (nucleo-canvas): header area layout (or a basic <header class="site-header"> without one), then <div id="main"> around a Nucleo page (the document has its own main) or <main id="main"> around single, archive, search and 404 layouts, with the before-content and after-content areas inside, then the footer area. No extra width or padding around documents: the width comes from nc/section.',
                'nc/section: <tag class="nc-section"><div class="nc-section__inner">children</div></tag>. The inner box has max-width var(--nc-container-lg), margin-inline auto and padding-inline var(--nc-space-4). Background and padding of the section go on the full-width outer tag.',
                'A section whose direct children include at least one section becomes a transparent wrapper: its inner box loses max-width and padding so the child sections reach the edges. Its other direct children (containers, headings, images) also go full width. Keep only sections as children of a wrapper, or give the other children their own section; for a single element set maxWidth "$container.lg", margin "0 auto" and padding on it.',
                'Links: the theme sets a { color: var(--nc-color-primary) } and keeps the browser underline. Remove it with textDecoration "none" on the block that has the typography group (nc/button and menu links have their own style).',
                'Box sizing is border-box everywhere. Headings use --nc-font-heading, body text --nc-font-body and --nc-text-base, line-height 1.6.',
                'LCP: Nucleo gives fetchpriority high to the first image of the page that is not decorative. Do not set it by hand.',
            ],
            'data' => [
                'css' => $themeCss,
            ],
        ];
    }

    /** @return array{topic:string,summary:string,rules:list<string>,data:array<string,mixed>} */
    private static function siteFiles(): array
    {
        return [
            'topic' => 'site-files',
            'summary' => 'Files of site-export, site-import and nucleo pull/push: relative path → JSON text.',
            'rules' => [
                'Folders: sections/<slug>.json, pages/<slug>.json, posts/<slug>.json, layouts/<slug>.json, menus/<slug>.json, media/manifest.json, rules/assets.json, site.json. Content items and terms (content/<type>/<slug>.json, terms/<taxonomy>.json) go through content-upsert and terms-set (nucleo push does it). Their files carry their own "base" (the hash read by nucleo pull), not the base of site.json: a conflict on them is solved with nucleo pull (pull --only does not take content/ and terms/) or nucleo push --force <file>.',
                'Document file: {"schema": 1, "kind": "page"|"layout"|"section"'
                    . ', "meta": {...}, "params"?: {...} (sections), "root": {node}, "wp": {...}}.',
                'wp block (required): slug, postType (page, post, nucleo_layout, nucleo_section), title, status ("publish" publishes, anything else is a draft); optional lang and translations {lang: path}. Pages: parent, the slug or path of the parent page ("azienda", "chi-siamo/azienda"), null for a top-level page (export writes it); without the key the parent stays as it is. The parent file is imported before its children; a parent neither on the site nor in pages/ (same language) gives an error, so does a cycle; a published child of an unpublished parent gives a warning. Menus, links and layout conditions then use the path ("page:azienda/controllo-qualita").',
                'meta keys read by Nucleo: layouts area (header, footer, before-content, after-content, single, archive, search, notfound'
                    . '; with WooCommerce also product and product-archive) and conditions {include, exclude, priority} (condition types and format: topic layouts)'
                    . '. Other keys are kept and ignored: meta.layout has no effect. A page always gets the header and footer layouts whose conditions match it; to remove them on one page, exclude the page in the layout conditions.',
                'meta.seo {title, description, image, noindex} sets the SEO of a document. It is ignored when Yoast or Rank Math is active.',
                'meta.seo.schema: list of schema.org nodes of the page (Service, Product, ContactPage...), each with @type. The string "@business" in a node points to the business of the site (nucleo-site-options-set business), which is also printed on the homepage. A BreadcrumbList from the page parents is added to every page except the homepage; meta.seo.breadcrumbs false turns it off, and a page with nc/breadcrumbs keeps the one of the block. Pages with noindex leave the WordPress sitemap. All of this is off when Yoast or Rank Math is active.',
                'Import order: site.json (tokens, then site options at the end), media/manifest.json, menus (created empty), sections (a section inserted in another first), pages, posts, layouts, then menu items, rules/assets.json, languages, site options. A page created in the same import resolves page:<slug> in the next files and in the menu items.',
                'Each file is created or updated by postType and slug. A file whose post changed on the site after the export (site.json "base" hash), for example with doc-patch or layout-conditions-set, and not locally is skipped with a warning: the site version stays. A file changed both locally and on the site is skipped with code conflict. The page parent counts as part of the document. To keep the site version: nucleo pull --only <file> rewrites that file and its base (a different local copy goes to .nucleo-backup/), then apply your change again. To overwrite the site version: nucleo push --force <file> (site-import force: ["<file>"]; force true overwrites every file). nucleo push writes the new base (base, tokensBase, siteBase) into site.json after a successful push, so the next push does not conflict with its own changes.',
                'site.json "site" (title, tagline, homepage, postsPage, language, dateFormat, timeFormat): with "siteBase" only the keys changed locally since the last pull or push are written. A key changed only on the site (for example with site-options-set) stays as it is on the site, also in the following pushes, until a pull brings it into site.json. A key changed both locally and on the site gives code conflict; force (or force ["site.json"]) writes it. dateFormat and timeFormat are PHP date formats ("j F Y", "H:i"), as in site-options-set.',
                'Menu file menus/<slug>.json: {"name", "items": [{"label", one of page | post | ref | url, "children"?}]}. page and post take a slug (a child page takes its path, "services/consulting"); ref takes <post-type>:<slug> for any public content type, term:<taxonomy>/<slug>, archive:<post-type> or home; url is for external links. Items to content, terms and archives are stored by ID: links stay correct when a slug changes; home is stored as the site address. Export skips items whose target is unpublished, deleted or private (site-export "skipped"). menu-set takes the same items.',
                'dryRun counts creations and updates without writing and returns the same errors per file: read them before the real import.',
                'Errors are per file and do not stop the other files: {file, message, code: error|conflict|duplicate|not_json, guide?}.',
                'site-import accepts acknowledge per file path; nucleo push --acknowledge <file> sends it after a confirmation typed by a human in the terminal (refused without a TTY). See topic acknowledge.',
                'References in files: links page:<slug>, <post-type>:<slug>, term:<taxonomy>/<slug>, home; media "media:<file name>" (resolved through media/manifest.json); sections by slug in nc/section-ref; menus in nc/menu settings.menu as "menu:<slug>" or the plain slug ("principale"), the slug being the menus/<slug>.json file name or the slug given to menu-set; doc-create and doc-patch accept the slug too and store the menu ID.',
            ],
            'data' => [
                'order' => ['site.json', 'media/manifest.json', 'menus/', 'sections/', 'pages/', 'posts/', 'layouts/', 'menu items', 'rules/assets.json', 'site options'],
                'postTypes' => ['page', 'post', 'nucleo_layout', 'nucleo_section'],
            ],
        ];
    }

    /** @return array{topic:string,summary:string,rules:list<string>,data:array<string,mixed>} */
    private static function acknowledge(): array
    {
        return [
            'topic' => 'acknowledge',
            'summary' => 'acknowledge: [{"rule", "node", "reason"}] accepts a blocking quality issue with a reason (for example the user consent).',
            'rules' => [
                'Accepted by doc-create, doc-patch, doc-publish and quality-check, and by site-import per file path. nucleo push --acknowledge asks a human to confirm each entry in the terminal and is refused without a TTY.',
                'Saved with the document (post meta _nucleo_ack of that document), one entry per rule and node. It stores a fingerprint of the node: it stays valid for later saves, imports and recompilations while the node does not change; when the node changes (content, settings, style), is removed or fixed, the entry is dropped and a new confirmation is needed.',
                'Reusable sections: an acknowledge given when saving the section covers only the section document. Each page or layout that inserts the section needs its own acknowledge, with node = id of its nc/section-ref node: it covers every node inside that instance. One per instance, because parameters (colors, texts) can change the result.',
                'Some rules cannot be forced: acknowledge does not cover them, fix the issue.',
                'Ask the user before acknowledging accessibility or consent issues and put the answer in reason.',
            ],
            'data' => [],
        ];
    }

    /** @return array{topic:string,summary:string,rules:list<string>,data:array<string,mixed>} */
    private static function components(): array
    {
        return [
            'topic' => 'components',
            'summary' => 'PHP components (nucleo component new): data sources, actions and blocks, in the site plugin nucleo-components, deployed outside MCP. It is the only place for PHP of the site.',
            'rules' => [
                'Registration: data sources and actions through the hook nucleo/register_components, blocks through nucleo/register_blocks. nucleo component new writes the code and the registration for all three kinds; nucleo-components-list shows data sources and actions, catalog-get shows component blocks with the core ones.',
                'Data source (component new data-source): returns items (arrays) for nc/collection with source component; the card reads them with bind source item. Read on every request, cached for schema().ttl seconds.',
                'Action (component new action): runs when an nc/native-form is submitted.',
                'Block (component new block): its renderer runs when the document is saved and returns static HTML. It is not called on each request and does not see the current post, so it cannot render per-post data. For per-post data use binds, nc/repeater, nc/query with relatedTo, nc/post-meta, or a data source with nc/collection.',
                'Component code never travels through MCP, REST or push: nucleo component deploy copies it locally; on other environments a person deploys it.',
                'No mu-plugins, no code in functions.php or in the theme, no snippet plugins, no CSS or scripts printed in wp_head or wp_footer. They escape push, pull, quality checks and the asset optimizer, and theme updates overwrite them. When Nucleo cannot do something, tell the user it is a Nucleo gap, propose the closest native way (style keys, Hover and Current states, nc/toc, site icons, meta.seo.schema) and write a component only with their consent.',
            ],
            'data' => [],
        ];
    }
}
