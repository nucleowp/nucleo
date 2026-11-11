<?php

declare(strict_types=1);

namespace Nucleo\Runtime;

defined('ABSPATH') || exit;

use Nucleo\Content\PageSettings;
use Nucleo\Layout\LayoutResolver;
use Nucleo\Layout\RequestContext;
use Nucleo\Storage\ArtifactStore;
use Nucleo\Storage\ArtifactStoreFactory;

/**
 * Aree del tema (header, before-content, after-content, footer, product, product-archive, single, search, archive, 404) con il layout risolto per la richiesta.
 * Legge solo gli artefatti layouts e layout-<id>: nessuna query di contenuto, nessun Services.
 */
final class Areas
{
    /** Ordine di risoluzione: product prima di single, search prima di archive (che ne è il fallback). */
    private const AREAS = [
        'header', 'before-content', 'after-content', 'footer', 'product', 'product-archive', 'single', 'search', 'archive', 'notfound',
    ];
    private const ARCHIVE_KINDS = ['term_archive', 'post_type_archive', 'author_archive', 'blog'];
    private const PRODUCT_TERM_ARCHIVES = ['product_cat', 'product_tag'];

    /** Chiave della richiesta per cui sono stati preparati gli HTML. */
    private static string $key = '';

    /** @var array<string,string> HTML con gli slot riempiti, per area. */
    private static array $html = [];

    /** @var array<string,list<string>> blocchi dinamici (slot) del layout di ogni area della richiesta. */
    private static array $blocks = [];

    /** Risolve le aree della richiesta corrente, accoda stili e script e riempie gli slot. */
    public static function prepare(): void
    {
        self::$key = self::requestKey();
        self::$html = [];
        self::$blocks = [];

        if (self::documentHasNoLayout()) {
            return;
        }

        $artifact = self::store()->get('layouts');
        if ($artifact === null) {
            return;
        }
        $resolver = new LayoutResolver(
            is_array($artifact['map'] ?? null) ? $artifact['map'] : [],
            is_array($artifact['exclude'] ?? null) ? $artifact['exclude'] : [],
            is_array($artifact['translations'] ?? null) ? $artifact['translations'] : [],
        );
        $post = get_queried_object();
        $postId = is_singular() && $post instanceof \WP_Post ? $post->ID : 0;

        foreach (self::plan($resolver, RequestContext::current(), $postId, null, class_exists('WooCommerce')) as $area => $hit) {
            $layout = self::store()->get('layout-' . $hit['layout']);
            if ($layout === null) {
                continue;
            }
            $rt = is_array($layout['rt'] ?? null) ? $layout['rt'] : [];
            Frontend::ensureTokens();
            self::enqueue($rt);
            Slots::setDocument((int) $hit['layout']);
            $html = MenuCurrent::apply(self::fill((string) ($layout['html'] ?? ''), $rt));
            self::$html[$area] = $html;
            self::$blocks[$area] = array_values(array_unique(array_column(self::slots($rt['slots'] ?? null), 'block')));
        }
    }

    /**
     * Blocchi dinamici del layout che serve l'area nella richiesta corrente (es. woo/gallery per "product").
     * Null se l'area non ha un layout Nucleo.
     *
     * @return list<string>|null
     */
    public static function slotBlocks(string $area): ?array
    {
        if (self::$key !== self::requestKey()) {
            self::prepare();
        }

        return self::$blocks[$area] ?? null;
    }

    /** HTML dell'area con gli slot riempiti, oppure stringa vuota senza layout. */
    public static function render(string $area): string
    {
        if (self::$key !== self::requestKey()) {
            self::prepare();
        }

        return self::$html[$area] ?? '';
    }

    /** Area esclusa dalla richiesta: documento con layout "none" o scelta "none" della pagina per quell'area. */
    public static function disabled(string $area): bool
    {
        $post = get_queried_object();
        $postId = is_singular() && $post instanceof \WP_Post ? $post->ID : 0;

        return self::documentHasNoLayout() || self::pageOverride($area, $postId) === false;
    }

    /** Una delle aree preparate porta un main: il tema non ne aggiunge un altro. */
    public static function hasMain(): bool
    {
        if (self::$key !== self::requestKey()) {
            self::prepare();
        }
        foreach (self::$html as $html) {
            if (str_contains($html, '<main')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Single, archive, search e 404 valgono solo per il loro tipo di richiesta; before-content e after-content
     * per contenuti singoli e archivi; header e footer per tutte. Archive vale anche per la ricerca (fallback di search).
     * Product e product-archive valgono solo con WooCommerce attivo.
     *
     * @param array<string,mixed> $context
     */
    public static function applies(string $area, array $context, bool $woocommerce): bool
    {
        $kind = (string) ($context['kind'] ?? '');

        return match ($area) {
            'product' => $woocommerce && $kind === 'singular' && ($context['type'] ?? null) === 'product',
            'product-archive' => $woocommerce && self::isProductArchive($context),
            'single' => $kind === 'singular',
            'before-content', 'after-content' => $kind === 'singular' || in_array($kind, self::ARCHIVE_KINDS, true),
            'archive' => in_array($kind, self::ARCHIVE_KINDS, true) || $kind === 'search',
            'search' => $kind === 'search',
            'notfound' => $kind === '404',
            default => true,
        };
    }

    /**
     * Archivio del negozio (post type archive di product) o archivio di categoria o tag di prodotto.
     *
     * @param array<string,mixed> $context
     */
    private static function isProductArchive(array $context): bool
    {
        $kind = $context['kind'] ?? null;
        if ($kind === 'post_type_archive') {
            return ($context['type'] ?? null) === 'product';
        }

        return $kind === 'term_archive' && in_array($context['taxonomy'] ?? null, self::PRODUCT_TERM_ARCHIVES, true);
    }

    /**
     * Scelta della pagina per l'area (impostazioni Nucleo della pagina): null = regole del sito,
     * false = nessun layout, altrimenti il layout scelto.
     * @return array{layout:int,matched:string}|false|null
     */
    private static function pageOverride(string $area, int $postId): array|false|null
    {
        // Senza impostazioni salvate PageSettings non si carica: il front-end conta i file inclusi.
        if ($postId <= 0 || !metadata_exists('post', $postId, '_nucleo_page')) {
            return null;
        }
        $choice = PageSettings::layoutFor($postId, $area);
        if ($choice === null) {
            return null;
        }

        return $choice === 0 ? false : ['layout' => $choice, 'matched' => 'page'];
    }

    /** Documento con layout "none": nessuna area per la richiesta, header e footer compresi. */
    private static function documentHasNoLayout(): bool
    {
        $post = get_queried_object();

        return is_singular() && $post instanceof \WP_Post && self::docHasNoLayout($post->ID, null);
    }

    /**
     * Documento con layout "none". $doc è il documento da controllare (una pagina non ancora salvata);
     * null legge quello salvato del post.
     * @param array<string,mixed>|null $doc
     */
    private static function docHasNoLayout(int $postId, ?array $doc): bool
    {
        if ($doc === null && $postId > 0) {
            $raw = get_post_meta($postId, '_nucleo_doc', true);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $doc = is_array($decoded) ? $decoded : null;
        }

        return is_array($doc) && ($doc['layout'] ?? null) === 'none';
    }

    /**
     * Layout di ogni area per una richiesta, con le regole del frontend: layout "none" del documento, scelta
     * della pagina (anche "none"), product al posto di single, product-archive e search al posto di archive.
     * La usa anche il controllo di qualità (PageComposer), così valuta la pagina che il frontend compone.
     *
     * @param array<string,mixed> $context contesto di RequestContext
     * @param array<string,mixed>|null $doc documento da usare al posto di quello salvato del post
     * @return array<string,array{layout:int,matched:string}> area => layout, nell'ordine di risoluzione
     */
    public static function plan(LayoutResolver $resolver, array $context, int $postId, ?array $doc, bool $woocommerce): array
    {
        if (self::docHasNoLayout($postId, $doc)) {
            return [];
        }
        $plan = [];
        foreach (self::AREAS as $area) {
            if (!self::applies($area, $context, $woocommerce)) {
                continue;
            }
            if ($area === 'single' && isset($plan['product'])) {
                continue;
            }
            if ($area === 'archive' && (isset($plan['product-archive']) || isset($plan['search']))) {
                continue;
            }
            $hit = self::pageOverride($area, $postId) ?? $resolver->resolve($area, $context);
            if (is_array($hit)) {
                // Un layout tradotto non entra nella mappa: si serve la traduzione del layout scelto.
                $hit['layout'] = $resolver->localized($hit['layout'], $context);
                $plan[$area] = $hit;
            }
        }

        return $plan;
    }

    private static function requestKey(): string
    {
        return did_action('wp') . ':' . get_queried_object_id() . ':'
            . md5((string) wp_json_encode(RequestContext::current()));
    }

    /** @param array<string,mixed> $rt */
    private static function enqueue(array $rt): void
    {
        foreach (self::strings($rt['styles'] ?? null) as $url) {
            wp_enqueue_style(self::handle($url), $url, [], NUCLEO_VERSION);
        }
        foreach (self::strings($rt['eager'] ?? null) as $url) {
            wp_enqueue_script(self::handle($url), $url, [], NUCLEO_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
        }
        Frontend::queueLazy(Frontend::lazyEntries($rt['lazy'] ?? null));
    }


    /** @param array<string,mixed> $rt */
    private static function fill(string $html, array $rt): string
    {
        $slots = self::slots($rt['slots'] ?? null);
        if ($slots === []) {
            return $html; // nessuno slot: SlotRenderer non si carica
        }

        return (new SlotRenderer())->fill(
            $html,
            $slots,
            static fn (string $block, array $data): string => Slots::render($block, $data),
        );
    }

    /** @return array<int,array{block:string,data:array<string,mixed>}> */
    private static function slots(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $index => $slot) {
            if (is_int($index) && is_array($slot) && is_string($slot['block'] ?? null) && Slots::known($slot['block'])) {
                $out[$index] = [
                    'block' => $slot['block'],
                    'data' => is_array($slot['data'] ?? null) ? $slot['data'] : [],
                ];
            }
        }

        return $out;
    }

    /** @return non-empty-string */
    private static function handle(string $url): string
    {
        return 'nucleo-' . substr(md5($url), 0, 8);
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private static function store(): ArtifactStore
    {
        return ArtifactStoreFactory::create(\Nucleo\Storage\ArtifactStoreFactory::base());
    }
}
