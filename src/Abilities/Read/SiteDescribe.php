<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

defined('ABSPATH') || exit;

use Nucleo\Quality\Budgets;
use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Content\ContentTypes;
use Nucleo\Forms\DevMail;
use Nucleo\Layout\LayoutConditions;
use Nucleo\Layout\PostTypes;
use Nucleo\Services;
use Nucleo\Storage\StoreMaintenance;

/** nucleo/site-describe: riepilogo del sito per l'AI. */
final class SiteDescribe implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/site-describe';
    }

    public function args(): array
    {
        return [
            'label' => __('Describe site', 'nucleo'),
            'description' => 'Name, URL, language, date and time formats, environment type, Nucleo version, breakpoints, tokens, available blocks, number of documents, and the content types and taxonomies with registeredBy: nucleo-model (declared with model-set), wordpress, plugin-or-theme (registered by another plugin or the theme; modeled = true when a Nucleo field group targets it), or conflict (declared in the model but registered first by other code). Only the types the current user can edit. warnings lists site settings that make the pages look wrong (language pack missing, English date format on a site in another language). cache.empty is true when the Nucleo cache misses tokens or layouts: run cache-rebuild. layouts lists the published layouts with area and number of conditions. plugins lists the detected integrations (woocommerce, polylang, wpml, yoast, rankmath, acf). Read only. To change the WordPress site options (title, tagline, homepage, date formats, icons) use nucleo/site-options-set; tokens use nucleo/tokens-set. The Nucleo plugin settings (editor, performance, quality rules, captcha) are in nucleo/settings-get.',
            'input_schema' => ['type' => 'object', 'additionalProperties' => false],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok', 'name', 'url', 'language', 'environment', 'nucleoVersion', 'breakpoints', 'tokens', 'blocks', 'documentCount', 'content', 'cache', 'layouts', 'plugins'],
                'properties' => [
                    'ok' => ['type' => 'boolean', 'const' => true],
                    'name' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                    'language' => ['type' => 'string'],
                    'dateFormat' => ['type' => 'string'],
                    'timeFormat' => ['type' => 'string'],
                    'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'environment' => ['type' => 'string', 'enum' => ['local', 'development', 'staging', 'production']],
                    'nucleoVersion' => ['type' => 'string'],
                    'breakpoints' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'integer', 'minimum' => 0],
                    ],
                    'tokens' => ['type' => 'object'],
                    'blocks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['name', 'title', 'category'],
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'category' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'documentCount' => ['type' => 'integer', 'minimum' => 0],
                    'cache' => [
                        'type' => 'object',
                        'required' => ['empty'],
                        'properties' => [
                            'empty' => ['type' => 'boolean', 'description' => 'The Nucleo cache misses tokens or layouts while the database has Nucleo content: the public site shows no Nucleo header, footer or token CSS.'],
                            'message' => ['type' => 'string'],
                        ],
                    ],
                    'content' => [
                        'type' => 'object',
                        'properties' => [
                            'postTypes' => ['type' => 'array', 'items' => self::contentEntry()],
                            'taxonomies' => ['type' => 'array', 'items' => self::contentEntry()],
                        ],
                    ],
                    'layouts' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'title', 'area', 'conditions'],
                            'properties' => [
                                'id' => ['type' => 'integer', 'minimum' => 1],
                                'title' => ['type' => 'string'],
                                'area' => ['type' => 'string'],
                                'conditions' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Number of conditions of the layout.'],
                            ],
                        ],
                    ],
                    'plugins' => [
                        'type' => 'array',
                        'description' => 'Detected integrations: woocommerce, polylang, wpml, yoast, rankmath, acf.',
                        'items' => ['type' => 'string', 'enum' => ['woocommerce', 'polylang', 'wpml', 'yoast', 'rankmath', 'acf']],
                    ],
                    'quality' => [
                        'type' => 'array',
                        'description' => 'Ids of the active quality rules.',
                        'items' => ['type' => 'string'],
                    ],
                    'budgets' => [
                        'type' => 'object',
                        'description' => 'Performance budgets of the quality rules: css and js in bytes served by Nucleo, dom in estimated nodes. Filters nucleo/quality/{css,js,dom}_budget.',
                        'required' => ['css', 'js', 'dom'],
                        'properties' => [
                            'css' => ['type' => 'integer', 'minimum' => 0],
                            'js' => ['type' => 'integer', 'minimum' => 0],
                            'dom' => ['type' => 'integer', 'minimum' => 0],
                        ],
                    ],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function execute(array $input): array
    {
        $blocks = [];
        foreach (Services::registry()->all() as $manifest) {
            $blocks[] = ['name' => $manifest->name, 'title' => $manifest->title, 'category' => $manifest->category];
        }

        $documents = new \WP_Query([
            'post_type' => 'any',
            'post_status' => 'any',
            'meta_key' => '_nucleo_doc',
            'fields' => 'ids',
            'posts_per_page' => 1,
        ]);

        $notice = self::cacheNotice();

        return [
            'ok' => true,
            'name' => (string) get_bloginfo('name'),
            'url' => home_url('/'),
            'language' => (string) get_bloginfo('language'),
            'dateFormat' => (string) get_option('date_format'),
            'timeFormat' => (string) get_option('time_format'),
            'warnings' => array_merge(
                self::warnings(get_locale(), (string) get_option('date_format'), array_values(get_available_languages())),
                self::layoutWarnings(),
                DevMail::warnings(),
            ),
            // wp_get_environment_type(): la CLI copia i componenti solo in local e development (R4.3).
            'environment' => wp_get_environment_type(),
            'nucleoVersion' => NUCLEO_VERSION,
            'breakpoints' => Services::breakpoints()->minWidths,
            'tokens' => Services::tokens()->toArray(),
            'blocks' => $blocks,
            'documentCount' => (int) $documents->found_posts,
            'content' => ContentTypes::inventory(true),
            'cache' => $notice === null ? ['empty' => false] : ['empty' => true, 'message' => $notice],
            'layouts' => self::activeLayouts(),
            'plugins' => self::integrations(),
            'quality' => Services::quality()->ruleIds(),
            'budgets' => Budgets::all(),
        ];
    }

    /**
     * Avviso per l'agente quando lo store è vuoto ma il database ha contenuti Nucleo (reset o import, cache cancellata,
     * nessuna visita in admin da allora). Null se lo store è a posto.
     */
    public static function cacheNotice(): ?string
    {
        if (!StoreMaintenance::isEmpty() || !StoreMaintenance::hasData()) {
            return null;
        }

        return __('Nucleo cache is empty: header, footer and tokens are not served on the public site. Run cache-rebuild.', 'nucleo');
    }

    /** @return array<string,mixed> */
    private static function contentEntry(): array
    {
        return [
            'type' => 'object',
            'required' => ['slug', 'label', 'modeled', 'registeredBy'],
            'properties' => [
                'slug' => ['type' => 'string'],
                'label' => ['type' => 'string'],
                'modeled' => ['type' => 'boolean'],
                'registeredBy' => ['type' => 'string', 'enum' => ContentTypes::ORIGINS],
            ],
        ];
    }

    /**
     * Impostazioni del sito che rendono le pagine in una lingua o un formato sbagliati.
     *
     * @param list<string> $installed lingue installate (get_available_languages)
     * @return list<string>
     */
    public static function warnings(string $locale, string $dateFormat, array $installed): array
    {
        if ($locale === 'en_US' || $locale === '') {
            return [];
        }
        $out = [];
        if (!in_array($locale, $installed, true)) {
            $out[] = sprintf('Language pack %s is not installed: dates and default labels stay in English. Run site-options-set {"language": "%s"}: it downloads the pack, also when the language is already set.', $locale, $locale);
        }
        if ($dateFormat === 'F j, Y') {
            $out[] = sprintf('date_format is "F j, Y", the English default: dates print in English word order ("ottobre 27, 2026") on a %s site. Set it with site-options-set {"dateFormat": "j F Y"} or site.json site.dateFormat. A later language change through site-options-set adapts a default format by itself.', $locale);
        }

        return $out;
    }

    /**
     * Layout salvati con condizioni che il runtime non applica (formato vecchio o sbagliato): restano utilizzabili,
     * ma le condizioni non valide non scelgono il layout.
     * @return list<string>
     */
    private static function layoutWarnings(): array
    {
        $ids = get_posts(['post_type' => PostTypes::LAYOUT, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']);
        $out = [];
        foreach ($ids as $id) {
            $doc = Services::documents()->get((int) $id)['document'] ?? null;
            $errors = is_array($doc) ? LayoutConditions::errorsOf(LayoutConditions::normalize($doc)) : [];
            if ($errors === []) {
                continue;
            }
            $first = $errors[0];
            $out[] = sprintf(
                'Layout %d "%s" has conditions the runtime does not apply (%s: %s). Fix them with layout-conditions-set; formats in catalog-get topic layouts.',
                (int) $id,
                get_the_title((int) $id),
                $first->path,
                $first->message . ($first->fix !== null ? ', ' . $first->fix : ''),
            );
        }

        return $out;
    }

    /**
     * Layout pubblicati con area e numero di condizioni.
     * @return list<array{id:int,title:string,area:string,conditions:int}>
     */
    private static function activeLayouts(): array
    {
        $posts = get_posts([
            'post_type' => PostTypes::LAYOUT,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        $out = [];
        foreach ($posts as $post) {
            $raw = get_post_meta($post->ID, '_nucleo_doc', true);
            $doc = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $meta = is_array($doc) && is_array($doc['meta'] ?? null) ? $doc['meta'] : [];
            $out[] = [
                'id' => (int) $post->ID,
                'title' => $post->post_title,
                'area' => is_string($meta['area'] ?? null) ? $meta['area'] : '',
                'conditions' => is_array($meta['conditions'] ?? null) ? count($meta['conditions']) : 0,
            ];
        }

        return $out;
    }

    /**
     * Integrazioni con plugin noti, rilevate dalle costanti o dalle classi che definiscono.
     * @return list<string>
     */
    public static function integrations(): array
    {
        $found = [
            'woocommerce' => class_exists('WooCommerce'),
            'polylang' => defined('POLYLANG_VERSION'),
            'wpml' => defined('ICL_SITEPRESS_VERSION'),
            'yoast' => defined('WPSEO_VERSION'),
            'rankmath' => defined('RANK_MATH_VERSION'),
            'acf' => class_exists('ACF') || function_exists('acf_get_setting'),
        ];

        return array_keys(array_filter($found));
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_posts');
    }
}
