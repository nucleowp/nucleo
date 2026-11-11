<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Site\SiteOptions;
use WP_Post;

/** nucleo/site-options-set: titolo, tagline, homepage e pagina degli articoli. Le pagine si indicano per slug. */
final class SiteOptionsSet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/site-options-set';
    }

    public function args(): array
    {
        return [
            'label' => __('Save site options', 'nucleo'),
            'description' => 'Saves the site title, tagline, homepage, posts page, date and time formats, the business shown in the structured data, the site icons and the modern image formats (avif, webp). Pages are given by slug and must be published. Every value is checked before anything is written. These are WordPress options, not Nucleo settings: the plugin settings (editor, performance, quality rules, captcha) go through nucleo/settings-set, the design tokens through nucleo/tokens-set.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Site title. Plain text.'],
                    'tagline' => ['type' => 'string', 'description' => 'Site tagline. Plain text.'],
                    'homepage' => ['type' => ['string', 'null'], 'description' => 'Slug of a published page for the static homepage, or null for the latest posts.'],
                    'postsPage' => ['type' => ['string', 'null'], 'description' => 'Slug of a published page that lists the posts, or null for none.'],
                    'dateFormat' => ['type' => 'string', 'minLength' => 1, 'description' => 'PHP date format of the site, e.g. "j F Y" (27 ottobre 2026) or "d/m/Y".'],
                    'timeFormat' => ['type' => 'string', 'minLength' => 1, 'description' => 'PHP time format of the site, e.g. "H:i".'],
                    'business' => [
                        'type' => ['object', 'null'],
                        'description' => 'The business behind the site, printed as schema.org JSON-LD on the homepage and on pages whose meta.seo.schema uses "@business". Keys are schema.org names: @type (default LocalBusiness, e.g. AutoPartsStore), name (required), legalName, vatID (e.g. IT01234567890), taxID, description, url, logo, image, telephone, email, address {streetAddress, addressLocality, addressRegion, postalCode, addressCountry}, geo {latitude, longitude}, openingHours (list such as "Mo-Fr 08:00-18:00"), foundingDate, areaServed, sameAs (list of URLs), priceRange. null removes it. Not printed when Yoast or Rank Math is active.',
                    ],
                    'icons' => [
                        'type' => 'object',
                        'description' => 'Site icons for nc/icon: name (a-z, 0-9, -) → SVG markup. The whole set is replaced. Colors become currentColor, so the icon takes the color style of the block. Scripts, styles, links and unknown elements are removed. A name of a built-in icon replaces it. To add or remove single icons use nucleo-icons-set.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'siteIcon' => [
                        'type' => ['string', 'null'],
                        'description' => 'Favicon and app icon (WordPress site_icon): URL of an image in the media library, square, at least 512×512 px. Upload it first with nucleo-media-upload. null removes it.',
                    ],
                    'imageFormats' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'enum' => ['avif', 'webp']],
                        'uniqueItems' => true,
                        'description' => 'Modern image formats generated next to the uploaded images: "avif", "webp" or both, in order of preference. Empty list turns them off. Optional: the original stays the fallback of <picture>. Files are generated on upload; existing images need nucleo-media-formats.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'site' => [
                        'type' => 'object',
                        'description' => 'title, tagline, homepage (slug or null), postsPage (slug or null), language, dateFormat, timeFormat, business (or null), icons, imageFormats.',
                    ],
                    'error' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $result = SiteOptions::apply($input, self::publishedPageId(...));
        if (is_wp_error($result)) {
            return ['ok' => false, 'error' => ['code' => $result->get_error_code(), 'message' => $result->get_error_message()]];
        }

        return ['ok' => true, 'site' => SiteOptions::get()];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('manage_options');
    }

    /** Slug → ID di una pagina pubblicata, o null se non esiste. */
    private static function publishedPageId(string $slug): ?int
    {
        $page = get_page_by_path($slug, OBJECT, 'page');

        return $page instanceof WP_Post && $page->post_status === 'publish' ? $page->ID : null;
    }
}
