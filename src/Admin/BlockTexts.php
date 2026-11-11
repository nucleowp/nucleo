<?php

declare(strict_types=1);

namespace Nucleo\Admin;

defined('ABSPATH') || exit;

/**
 * Titoli e descrizioni dei blocchi del plugin nella lingua dell'utente.
 * File generato da bin/block-texts dai block.nucleo.json: non modificarlo a mano.
 */
final class BlockTexts
{
    /** @return array{title:string,description:string}|null null per un blocco che non è del plugin */
    public static function get(string $name): ?array
    {
        return match ($name) {
            'nc/accordion' => [
                'title' => __('Accordion', 'nucleo'),
                'description' => __('List of expandable sections based on details and summary.', 'nucleo'),
            ],
            'nc/archive-description' => [
                'title' => __('Archive description', 'nucleo'),
                'description' => __('Description of a category, tag or archive. Empty if the archive has no description. Dynamic: read on every request.', 'nucleo'),
            ],
            'nc/archive-title' => [
                'title' => __('Archive title', 'nucleo'),
                'description' => __('Title of a category, tag or archive, without prefix. With search it shows the query. Dynamic: read on every request.', 'nucleo'),
            ],
            'nc/breadcrumbs' => [
                'title' => __('Breadcrumbs', 'nucleo'),
                'description' => __('Navigation path: Home, optional parent or post type archive, taxonomy term, post ancestors, current page. Uses Yoast or Rank Math if active, otherwise an internal generator. Dynamic.', 'nucleo'),
            ],
            'nc/button' => [
                'title' => __('Button', 'nucleo'),
                'description' => __('Link styled as a button, or an action button.', 'nucleo'),
            ],
            'nc/collection-card' => [
                'title' => __('Collection card', 'nucleo'),
                'description' => __('Card of a nc/collection: its one child is repeated for each post. Only inside nc/collection.', 'nucleo'),
            ],
            'nc/collection-count' => [
                'title' => __('Collection count', 'nucleo'),
                'description' => __('Number of results of a nc/collection. Only inside nc/collection.', 'nucleo'),
            ],
            'nc/collection-empty' => [
                'title' => __('Collection empty state', 'nucleo'),
                'description' => __('Content shown by a nc/collection when no post matches. Only inside nc/collection.', 'nucleo'),
            ],
            'nc/collection-reset' => [
                'title' => __('Collection reset', 'nucleo'),
                'description' => __('Link that clears the filters of a nc/collection. Only inside nc/collection.', 'nucleo'),
            ],
            'nc/collection' => [
                'title' => __('Collection', 'nucleo'),
                'description' => __('Filterable and sortable list of posts with a card, pagination or load-more, counts and a browser script. Filters and results update without reloading the page.', 'nucleo'),
            ],
            'nc/container' => [
                'title' => __('Container', 'nucleo'),
                'description' => __('Flex or grid container for columns and groups of blocks.', 'nucleo'),
            ],
            'nc/divider' => [
                'title' => __('Divider', 'nucleo'),
                'description' => __('Horizontal line separating two blocks.', 'nucleo'),
            ],
            'nc/embed' => [
                'title' => __('Embed', 'nucleo'),
                'description' => __('YouTube, Vimeo, Google Maps or another oEmbed provider known to WordPress, with a static facade; the iframe loads only on click.', 'nucleo'),
            ],
            'nc/form' => [
                'title' => __('Form', 'nucleo'),
                'description' => __('Form from an external plugin (Contact Form 7, Gravity Forms, WPForms, Fluent Forms) inserted via shortcode.', 'nucleo'),
            ],
            'nc/heading' => [
                'title' => __('Heading', 'nucleo'),
                'description' => __('Section heading from h1 to h6.', 'nucleo'),
            ],
            'nc/icon' => [
                'title' => __('Icon', 'nucleo'),
                'description' => __('Inline SVG icon: a site icon, or one of the built-in icons. A site icon with the name of a built-in icon replaces it.', 'nucleo'),
            ],
            'nc/image' => [
                'title' => __('Image', 'nucleo'),
                'description' => __('Image with srcset and sizes, LCP priority or deferred loading.', 'nucleo'),
            ],
            'nc/list' => [
                'title' => __('List', 'nucleo'),
                'description' => __('Bulleted list, optionally with an icon per item.', 'nucleo'),
            ],
            'nc/logo' => [
                'title' => __('Logo', 'nucleo'),
                'description' => __('Site logo with explicit dimensions, or the site name. Linked to the home page.', 'nucleo'),
            ],
            'nc/mega-panel' => [
                'title' => __('Mega panel', 'nucleo'),
                'description' => __('Full-width panel linked to an nc/menu item. Shown on mouse hover or focus from 768px.', 'nucleo'),
            ],
            'nc/menu' => [
                'title' => __('Menu', 'nucleo'),
                'description' => __('WordPress menu with nested or dropdown submenus. Updates when the menu changes.', 'nucleo'),
            ],
            'nc/native-form' => [
                'title' => __('Native form', 'nucleo'),
                'description' => __('Native form with fields, steps and actions: email code check, save, email, webhook, create post.', 'nucleo'),
            ],
            'nc/offcanvas' => [
                'title' => __('Off-canvas', 'nucleo'),
                'description' => __('Trigger button that opens a side panel holding child blocks. The panel is a modal dialog.', 'nucleo'),
            ],
            'nc/pagination' => [
                'title' => __('Pagination', 'nucleo'),
                'description' => __('Links to the pages of the main query. Outputs nothing with a single page. Dynamic.', 'nucleo'),
            ],
            'nc/post-content' => [
                'title' => __('Post content', 'nucleo'),
                'description' => __('Content of the current post or page. Dynamic: read on every request.', 'nucleo'),
            ],
            'nc/post-image' => [
                'title' => __('Featured image', 'nucleo'),
                'description' => __('Featured image of the current post. Dynamic: read on every request.', 'nucleo'),
            ],
            'nc/post-meta' => [
                'title' => __('Post meta', 'nucleo'),
                'description' => __('Date, author, categories and custom taxonomy terms of the current post. Dynamic: read on every request.', 'nucleo'),
            ],
            'nc/post-title' => [
                'title' => __('Post title', 'nucleo'),
                'description' => __('Title of the current post or page, from h1 to p. Dynamic: read on every request.', 'nucleo'),
            ],
            'nc/query' => [
                'title' => __('Post list', 'nucleo'),
                'description' => __('Post list. Without children: card with image, linked title and excerpt. With one child: the child is the card, repeated for each post. Static: updates when posts change. With relatedTo (a taxonomy slug): lists the other posts of the same type that share a term of that taxonomy with the current post, computed at request time and cached per post.', 'nucleo'),
            ],
            'nc/repeater' => [
                'title' => __('Repeater', 'nucleo'),
                'description' => __('Repeats its one child, the row, for each row of a repeater field of the current post. Without rows it shows the empty text, if set.', 'nucleo'),
            ],
            'nc/search' => [
                'title' => __('Search form', 'nucleo'),
                'description' => __('Site search form. Shows the current query in the results. Dynamic.', 'nucleo'),
            ],
            'nc/section-ref' => [
                'title' => __('Reusable section', 'nucleo'),
                'description' => __('Inserts a reusable section (nucleo_section) with its own parameter values.', 'nucleo'),
            ],
            'nc/section' => [
                'title' => __('Section', 'nucleo'),
                'description' => __('Full-width band with an inner container.', 'nucleo'),
            ],
            'nc/slider' => [
                'title' => __('Carousel', 'nucleo'),
                'description' => __('Series of slides: scroll-snap by slide (effect slide) or one slide at a time with a cross-fade (effect fade). Optional arrows, dots, autoplay with a pause button, loop and speed.', 'nucleo'),
            ],
            'nc/spacer' => [
                'title' => __('Spacing', 'nucleo'),
                'description' => __('Empty vertical space between two blocks.', 'nucleo'),
            ],
            'nc/tabs' => [
                'title' => __('Tabs', 'nucleo'),
                'description' => __('Tabs with a title and content. Without JavaScript all contents stay visible; with JavaScript only one is shown at a time.', 'nucleo'),
            ],
            'nc/text' => [
                'title' => __('Text', 'nucleo'),
                'description' => __('Paragraph with limited rich text: bold, italic, links, line breaks, lists.', 'nucleo'),
            ],
            'nc/toc' => [
                'title' => __('Table of contents', 'nucleo'),
                'description' => __('In-page table of contents: links to the sections of the page, with the current section highlighted while scrolling.', 'nucleo'),
            ],
            'nc/video' => [
                'title' => __('Video', 'nucleo'),
                'description' => __('YouTube or Vimeo video with a static thumbnail; the iframe loads only on click.', 'nucleo'),
            ],
            default => null,
        };
    }
}
