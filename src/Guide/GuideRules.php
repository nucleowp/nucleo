<?php

declare(strict_types=1);

namespace Nucleo\Guide;

/**
 * Catalogo delle fasi della guida (docs/16-guida-a-fasi.md): titolo, regole e condizioni di uscita.
 */
final class GuideRules
{
    public const LAST = 4;

    /**
     * @return array{title: string, rules: list<array{code: string, text: string, tools: list<string>}>, exit: list<string>}
     */
    public static function phase(int $n): array
    {
        return match ($n) {
            0 => self::phase0(),
            1 => self::phase1(),
            2 => self::phase2(),
            3 => self::phase3(),
            4 => self::phase4(),
            default => throw new \InvalidArgumentException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per chi sviluppa, mai stampato in HTML.
                sprintf('Guide phase must be between 0 and %d, got %d.', self::LAST, $n),
            ),
        };
    }

    /**
     * @param list<string> $tools
     * @return array{code: string, text: string, tools: list<string>}
     */
    private static function rule(string $code, string $text, array $tools = []): array
    {
        return ['code' => $code, 'text' => $text, 'tools' => $tools];
    }

    /** @return array{title: string, rules: list<array{code: string, text: string, tools: list<string>}>, exit: list<string>} */
    private static function phase0(): array
    {
        return [
            'title' => __('Template structure', 'nucleo'),
            'rules' => [
                self::rule(
                    'R0.1',
                    __('Two worlds. Saving validates, compiles HTML and CSS, and checks quality. The frontend reads only the result. Every write goes through the validator and the quality engine. An error names the node, the path and the rule.', 'nucleo'),
                ),
                self::rule(
                    'R0.2',
                    __('Document types: page and post (content), layout (header, footer, templates with conditions), section (reusable block with parameters), popup.', 'nucleo'),
                ),
                self::rule(
                    'R0.3',
                    __('A document is a tree of nodes with type, content, settings, responsive style and children: catalog-get {topic: documents} describes the format (kind, ids, children, section parameters, bind). catalog-list lists the blocks; catalog-get {name} shows the fields, style keys, HTML and CSS of a block; catalog-get {topic: styles} lists the style groups of every block; catalog-get {topic} also answers on tokens, bindings, visibility, data, theme, site files, acknowledge and components.', 'nucleo'),
                    ['nucleo/catalog-list', 'nucleo/catalog-get'],
                ),
                self::rule(
                    'R0.4',
                    __('Values that start with $ are tokens.', 'nucleo'),
                ),
                self::rule(
                    'R0.5',
                    __('Two channels with the same permissions and the same rules: the CLI (pull, editing files, push) for work on many files, the MCP for reads and point changes (doc-patch).', 'nucleo'),
                    ['nucleo pull', 'nucleo push', 'nucleo/doc-patch'],
                ),
                self::rule(
                    'R0.6',
                    __('Plugin and theme are not modified. Every customization goes through documents, content and, in phase 4, components.', 'nucleo'),
                ),
            ],
            'exit' => [
                __('You know the two worlds, the document model, the token syntax and the two channels.', 'nucleo'),
            ],
        ];
    }

    /** @return array{title: string, rules: list<array{code: string, text: string, tools: list<string>}>, exit: list<string>} */
    private static function phase1(): array
    {
        return [
            'title' => __('Tokens and components', 'nucleo'),
            'rules' => [
                self::rule(
                    'R1.1',
                    __('Before components, derive the tokens from the design: colors, fonts, type scale, spacing, radii, shadows, durations. Tools: nucleo tokens suggest <design.html>, then tokens-set.', 'nucleo'),
                    ['nucleo tokens suggest', 'nucleo/tokens-set'],
                ),
                self::rule(
                    'R1.2',
                    __('A value equal to a token uses $name. Push reports it as an error, and push --use-tokens replaces it.', 'nucleo'),
                    ['nucleo push --use-tokens'],
                ),
                self::rule(
                    'R1.3',
                    __('A value that appears at least 3 times in the design without a token is a missing token.', 'nucleo'),
                ),
                self::rule(
                    'R1.4',
                    __('Before creating, read the existing sections (section-list: parameters and uses), then catalog-list and kit-list. An acknowledge given when saving a section covers only the section: each page or layout that inserts it needs its own acknowledge, with the id of its nc/section-ref node.', 'nucleo'),
                    ['nucleo/section-list', 'nucleo/catalog-list', 'nucleo/kit-list'],
                ),
                self::rule(
                    'R1.5',
                    __('A design block that appears at least 2 times becomes a section with parameters, inserted with nc/section-ref. The guide calls two blocks equal when they have the same tree of at least 5 nodes: block types, heading levels, layouts and inserted sections; nc/section-ref nodes and lists do not count. Texts, images, links and styles become parameters. A block that differs on purpose takes "guide": {"distinct": "<reason>"}. The quality checks read the page with its sections expanded: an h1 inside a section counts.', 'nucleo'),
                ),
                self::rule(
                    'R1.6',
                    __('Variants (for example a cancelled event) are parameters of the same section.', 'nucleo'),
                ),
                self::rule(
                    'R1.7',
                    __('A section whose direct children include a section is a transparent wrapper: all its direct children reach the edges, also containers and headings. Bands with their own background are sibling sections, or the only children of one wrapper.', 'nucleo'),
                    ['nucleo/catalog-get'],
                ),
            ],
            'exit' => [
                __('Every repeated element has a section.', 'nucleo'),
                __('No value matches a token.', 'nucleo'),
            ],
        ];
    }

    /** @return array{title: string, rules: list<array{code: string, text: string, tools: list<string>}>, exit: list<string>} */
    private static function phase2(): array
    {
        return [
            'title' => __('Global parts', 'nucleo'),
            'rules' => [
                self::rule(
                    'R2.1',
                    __('Header and footer are layouts with area header/footer and condition entire_site. No page contains them.', 'nucleo'),
                    ['nucleo/doc-create', 'nucleo/layout-conditions-set'],
                ),
                self::rule(
                    'R2.2',
                    __('Variants are made with conditions and priorities, not with copies. Common parts are sections inserted in all variants. To hide a part on a breakpoint, use the node\'s visibility.', 'nucleo'),
                ),
                self::rule(
                    'R2.3',
                    __('Navigation uses menu-set and nc/menu. Internal links use references (page:<slug>, <post-type>:<slug>, term:<taxonomy>/<slug>, home), not hand-written paths. Menu items use page, post or ref (the same references plus archive:<post-type>); url only for external links.', 'nucleo'),
                    ['nucleo/menu-set'],
                ),
                self::rule(
                    'R2.4',
                    __('Bands repeated across several pages (newsletter, CTA) are sections with parameters, or layouts of the before-content and after-content areas.', 'nucleo'),
                ),
                self::rule(
                    'R2.5',
                    __('Before exiting: layout-explain on a page, a post and an archive; screenshots at 390 and 1440 px.', 'nucleo'),
                    ['nucleo/layout-explain'],
                ),
            ],
            'exit' => [
                __('Header, footer and menu apply to the whole site.', 'nucleo'),
                __('No page repeats the global parts.', 'nucleo'),
            ],
        ];
    }

    /** @return array{title: string, rules: list<array{code: string, text: string, tools: list<string>}>, exit: list<string>} */
    private static function phase3(): array
    {
        return [
            'title' => __('Real content', 'nucleo'),
            'rules' => [
                self::rule(
                    'R3.1',
                    __('An element that repeats with the same structure is content, not a copy in the page: type, taxonomies and fields with model-set. Boxes that bind different fields of the same item (the attributes of a card) are not repetitions.', 'nucleo'),
                    ['nucleo/model-set'],
                ),
                self::rule(
                    'R3.2',
                    __('Before creating a type, read model-get: "unmodeled" lists the types registered by other code, which model-set cannot declare. Standard pages and posts accept custom fields with location.postType: "page" or "post". Format: catalog-get {topic: model}.', 'nucleo'),
                    ['nucleo/model-get'],
                ),
                self::rule(
                    'R3.3',
                    __('Every type has a single layout connected to the fields and a list with nc/query or nc/collection, with the card from phase 1.', 'nucleo'),
                ),
                self::rule(
                    'R3.4',
                    __('The design texts go into content (content/<type>/<slug>.json, terms/<taxonomy>.json, or content-upsert and terms-set), not into the card.', 'nucleo'),
                    ['nucleo/content-upsert', 'nucleo/terms-set'],
                ),
                self::rule(
                    'R3.5',
                    __('Unique pages (home, about, contacts) remain page documents built with sections.', 'nucleo'),
                ),
                self::rule(
                    'R3.6',
                    __('The single layout follows the data, not a fixed count: nc/repeater repeats a row for each row of a repeater field; visibility when filled/empty hides a node whose field is empty (a PDF button without a file); nc/query with relatedTo lists items that share a term with the current one; nc/post-meta with tax:<taxonomy> shows linked terms; nc/breadcrumbs with archive, parent and taxonomy shows the full path. Rules: catalog-get {topic: data}.', 'nucleo'),
                    ['nucleo/catalog-get'],
                ),
            ],
            'exit' => [
                __('Every list in the design reads real content.', 'nucleo'),
                __('Every item has its own page.', 'nucleo'),
            ],
        ];
    }

    /** @return array{title: string, rules: list<array{code: string, text: string, tools: list<string>}>, exit: list<string>} */
    private static function phase4(): array
    {
        return [
            'title' => __('Dynamic parts', 'nucleo'),
            'rules' => [
                self::rule(
                    'R4.1',
                    __('Native blocks first. List with filters, sorting, grid or rows, "load more" → nc/collection; content tabs → nc/tabs; frequently asked questions, expandable panels → nc/accordion; carousel → nc/slider; contact forms, sign-ups, code via email → nc/native-form; search → nc/search and the layout of the search area. Form email: to = admin (default), an address, or field:<field>; replyTo field:<field>; settings.redirect is the page shown after sending.', 'nucleo'),
                ),
                self::rule(
                    'R4.2',
                    __('Then PHP components, only when native blocks, style keys and site options are not enough: nucleo component new data-source, action or block, then nucleo component check. They live in the site plugin nucleo-components, the only place for PHP of the site. A component block renders once, when the document is saved, and does not see the current post; per-post data comes from binds, the blocks of R3.6 or a data source with nc/collection.', 'nucleo'),
                    ['nucleo component new', 'nucleo component check'],
                ),
                self::rule(
                    'R4.3',
                    __('Component code never goes through MCP, REST or push. Locally, nucleo component deploy copies the plugin onto the local WordPress disk. On staging and production, the component arrives with the site migration or with the deploy (git), and the agent reports it as a step for a person.', 'nucleo'),
                    ['nucleo component deploy'],
                ),
                self::rule(
                    'R4.4',
                    __('Actions with external effects (webhook, email to third parties, content creation, component actions) require the user\'s consent before the form is published.', 'nucleo'),
                ),
                self::rule(
                    'R4.5',
                    __('A button with no link and no action is a fake control (see rule content.button.inert).', 'nucleo'),
                ),
                self::rule(
                    'R4.6',
                    __('No mu-plugins, no code in functions.php or in the theme, no snippets, no CSS or scripts printed in wp_head or wp_footer: they escape push, pull and the quality checks, and theme updates overwrite them. When Nucleo cannot do something: first style.parts and the style keys; then style.custom on the node for the CSS left; report every gap with nucleo-gap-report, also when style.custom covers it. For PHP behavior write a component (R4.2) only with the user\'s consent.', 'nucleo'),
                ),
                self::rule(
                    'R4.7',
                    __('With WooCommerce the shop is built with woo/* blocks: product page and archives are layouts of the areas product and product-archive; cart and checkout are the WooCommerce pages with woo/cart and woo/checkout. catalog-get {topic: woocommerce} gives the recipe of each page. Extra checkout fields → woo-checkout-fields-set; order note, terms, coupon, company, phone and address 2 → woo-checkout-parts-set. Never PHP or snippets for these.', 'nucleo'),
                    ['nucleo/catalog-get', 'nucleo/woo-checkout-fields-set', 'nucleo/woo-checkout-parts-set'],
                ),
            ],
            'exit' => [
                __('No design control stays fake.', 'nucleo'),
                __('Every form was tried with a test submission.', 'nucleo'),
            ],
        ];
    }
}
