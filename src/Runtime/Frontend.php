<?php

declare(strict_types=1);

namespace Nucleo\Runtime;

use Nucleo\Compiler\RebuildQueue;
use Nucleo\Seo\HeadMeta;
use Nucleo\Seo\StructuredData;
use Nucleo\Storage\ArtifactStore;
use Nucleo\Storage\ArtifactStoreFactory;

/**
 * Frontend di Nucleo: accoda stili, token e script di un post Nucleo e riempie gli slot.
 * Non carica Services, compilatore o registry: legge solo il meta _nucleo_rt e l'artefatto dei token.
 */
final class Frontend
{
    private const META_KEY = '_nucleo_rt';
    private const META_DOC = '_nucleo_doc';
    private const TOKENS_HANDLE = 'nucleo-tokens';
    private const LOADER_HANDLE = 'nucleo-loader';
    private const LAZY_STRATEGIES = ['visible', 'interaction', 'idle'];

    /** Post Nucleo della richiesta corrente e relativi slot (vuoti per gli altri post). */
    private static int $postId = 0;

    /** @var array<int,array{block:string,data:array<string,mixed>}> */
    private static array $slots = [];

    /** Preload dei font già inviato in questa richiesta (documento o layout). */
    private static bool $fontPreloaded = false;

    /** Ricompilazione già accodata in questa richiesta. */
    private static bool $queued = false;

    public static function boot(): void
    {
        // CssPreview si carica solo con il parametro di anteprima (CssPreview::PARAM): sul frontend normale non serve.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- carica CssPreview solo se c'è il parametro; i permessi si verificano lì.
        if (isset($_GET['nucleo_css'])) {
            CssPreview::register();
        }
        self::$postId = 0;
        self::$slots = [];

        // Modalità di emergenza: nessun asset Nucleo, wpautop e wptexturize restano al loro posto.
        if (self::safeMode()) {
            return;
        }
        $postId = is_singular() ? get_queried_object_id() : 0;
        $rt = $postId > 0 ? get_post_meta($postId, self::META_KEY, true) : null;
        if (is_array($rt)) {
            // Contesto del documento prima delle aree: un layout single con nc/post-content esegue the_content
            // dentro Areas::prepare, e filterContent deve già riconoscere il post e i suoi slot.
            self::$postId = $postId;
            self::$slots = self::slots($rt['slots'] ?? null);
            remove_filter('the_content', 'wpautop');
            remove_filter('the_content', 'wptexturize');
        }
        // Header, footer and template layouts: styles must be queued before wp_head.
        Areas::prepare();
        // Asset rules and purged CSS run after every plugin has enqueued.
        add_action('wp_enqueue_scripts', ['Nucleo\\Runtime\\AssetRuntime', 'apply'], 9999);
        if (!is_singular()) {
            return;
        }
        // Impostazioni Nucleo della pagina (larghezza, titolo nascosto, classi CSS): il filtro solo dove ci sono.
        if (metadata_exists('post', $postId, '_nucleo_page')) {
            add_filter('body_class', ['Nucleo\\Content\\PageSettings', 'bodyClasses']);
        }
        if (!is_array($rt)) {
            self::queueMissing($postId);
            return;
        }

        // meta.seo del documento: solo se presente, così le pagine senza SEO non caricano il modulo.
        $seo = $rt['seo'] ?? null;
        if (is_array($seo) && $seo !== []) {
            HeadMeta::register($seo);
        }
        // JSON-LD su ogni pagina singola con runtime: senza meta.seo il @graph può contenere comunque il breadcrumb.
        StructuredData::register(
            $postId,
            is_array($rt['seo'] ?? null) ? $rt['seo'] : [],
        );

        self::ensureTokens();
        self::sendCacheTag($postId);
        self::sendLcpPreload($postId);

        $plan = (new AssetPlan())->plan([[
            'styles' => self::strings($rt['styles'] ?? null),
            'scripts' => [
                ...array_map(
                    static fn (string $src): array => ['type' => '', 'src' => $src, 'strategy' => 'eager'],
                    self::strings($rt['eager'] ?? null),
                ),
                ...self::lazyEntries($rt['lazy'] ?? null),
            ],
        ]]);

        foreach ($plan['styles'] as $url) {
            $handle = self::handle($url);
            if ($handle === '') {
                continue;
            }
            wp_enqueue_style($handle, $url, [], NUCLEO_VERSION);
        }
        foreach ($plan['eager'] as $url) {
            $handle = self::handle($url);
            if ($handle === '') {
                continue;
            }
            wp_enqueue_script($handle, $url, [], NUCLEO_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
        }
        self::queueLazy($plan['lazy']);
    }

    /** True se la richiesta corrente mostra questo post come documento Nucleo. Per il tema: niente titolo né main propri. */
    public static function isDocument(int $postId): bool
    {
        return $postId !== 0 && self::$postId === $postId;
    }

    /** Token per i layout di header e footer anche sulle pagine non costruite con Nucleo: usano var(--nc-*). Con il preload dei font. */
    public static function ensureTokens(): void
    {
        $tokens = self::store()->get('tokens') ?? [];
        self::enqueueTokens($tokens);
        self::sendFontPreload($tokens);
    }

    public static function filterContent(string $content): string
    {
        $post = get_post();
        if (self::$postId === 0 || $post === null || $post->ID !== self::$postId) {
            return $content;
        }
        if (self::$slots !== []) {
            // Dentro un layout single il documento corrente è il layout: si ripristina dopo gli slot della pagina.
            $previous = Slots::document();
            Slots::setDocument(self::$postId);
            try {
                $content = (new SlotRenderer())->fill($content, self::$slots, [self::class, 'renderSlot']);
            } finally {
                Slots::setDocument($previous);
            }
        }

        // Voce corrente dei menu nc/menu: l'HTML compilato è lo stesso per tutte le pagine.
        return MenuCurrent::apply($content);
    }

    /**
     * Token inline prima di ogni altro stile: il loro handle e' accodato per primo.
     *
     * @param array<string,mixed> $tokens
     */
    private static function enqueueTokens(array $tokens): void
    {
        $css = $tokens['css'] ?? null;
        if (!is_string($css) || $css === '' || wp_style_is(self::TOKENS_HANDLE, 'registered')) {
            return;
        }
        wp_register_style(self::TOKENS_HANDLE, false, [], NUCLEO_VERSION);
        wp_enqueue_style(self::TOKENS_HANDLE);
        wp_add_inline_style(self::TOKENS_HANDLE, $css);
    }

    /**
     * Preload dei font nell'header Link: nessuna callback, solo se gli header non sono ancora partiti.
     * L'artefatto puo' contenere una URL (formato precedente) o una lista (body e heading).
     *
     * @param array<string,mixed> $tokens
     */
    private static function sendFontPreload(array $tokens): void
    {
        if (self::$fontPreloaded || headers_sent()) {
            return;
        }
        self::$fontPreloaded = true;

        $raw = $tokens['preload'] ?? null;
        $urls = is_string($raw) ? [$raw] : self::strings($raw);
        foreach ($urls as $url) {
            $safe = esc_url_raw($url);
            if ($safe === '') {
                continue;
            }
            header('Link: <' . $safe . '>; rel=preload; as=font; type="font/woff2"; crossorigin', false);
        }
    }

    /** Tag LiteSpeed del documento: la purga per post (CachePurger) svuota solo questa pagina. */
    private static function sendCacheTag(int $postId): void
    {
        if (!headers_sent()) {
            header('X-LiteSpeed-Tag: nucleo,nucleo-post-' . $postId, false);
        }
    }

    /** Preload della prima immagine con fetchpriority alto dell'HTML compilato del documento. */
    private static function sendLcpPreload(int $postId): void
    {
        if (headers_sent()) {
            return;
        }
        $lcp = self::lcpImage((string) get_post_field('post_content', $postId));
        if ($lcp === null) {
            return;
        }
        $src = esc_url_raw($lcp['src']);
        if ($src === '') {
            return;
        }

        $link = '<' . $src . '>; rel=preload; as=image';
        if (isset($lcp['srcset'])) {
            $link .= '; imagesrcset="' . $lcp['srcset'] . '"';
        }
        if (isset($lcp['sizes'])) {
            $link .= '; imagesizes="' . $lcp['sizes'] . '"';
        }
        header('Link: ' . $link, false);
    }

    /**
     * Prima <img fetchpriority="high"> dell'HTML: src, srcset e sizes decodificati.
     * Un valore con virgolette, <, > o caratteri di controllo viene scartato; senza src valido non c'è preload.
     *
     * @return array{src:string,srcset?:string,sizes?:string}|null
     */
    public static function lcpImage(string $html): ?array
    {
        if (preg_match_all('#<img\b[^>]*>#i', $html, $tags) === 0) {
            return null;
        }
        foreach ($tags[0] as $tag) {
            if (preg_match('/\sfetchpriority\s*=\s*(["\']?)high\1(?=[\s\/>])/i', $tag) !== 1) {
                continue;
            }

            $src = self::attr($tag, 'src');
            if ($src === null || $src === '') {
                return null;
            }
            $image = ['src' => $src];
            $srcset = self::attr($tag, 'srcset');
            if ($srcset !== null && $srcset !== '') {
                $image['srcset'] = $srcset;
            }
            $sizes = self::attr($tag, 'sizes');
            if ($sizes !== null && $sizes !== '') {
                $image['sizes'] = $sizes;
            }

            return $image;
        }

        return null;
    }

    /** Valore decodificato di un attributo HTML, o null se assente o non sicuro per un header Link. */
    private static function attr(string $tag, string $name): ?string
    {
        if (preg_match('/\s' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $m) !== 1) {
            return null;
        }
        $raw = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
        $value = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('/[\x00-\x1F\x7F"<>]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * Documento senza artefatto _nucleo_rt: accoda la ricompilazione, una sola volta per richiesta.
     */
    private static function queueMissing(int $postId): void
    {
        if (self::$queued) {
            return;
        }
        $doc = get_post_meta($postId, self::META_DOC, true);
        if (!is_string($doc) || $doc === '') {
            return;
        }
        self::$queued = true;
        RebuildQueue::enqueue([$postId]);
    }

    /**
     * Modalità di emergenza: costante NUCLEO_SAFE_MODE oppure opzione nucleo_safe_mode.
     * L'opzione si legge da alloptions (autoload): se manca non parte nessuna query, che il budget di RuntimeBudgetTest non ammette.
     */
    private static function safeMode(): bool
    {
        if (defined('NUCLEO_SAFE_MODE') && NUCLEO_SAFE_MODE) {
            return true;
        }

        return (bool) (wp_load_alloptions()['nucleo_safe_mode'] ?? false);
    }

    /**
     * Slot con blocco sconosciuto esclusi in slots(): SlotRenderer mantiene il contenuto di fallback.
     * Chiamato da SlotRenderer::fill come callable pubblico.
     *
     * @param array<string,mixed> $data
     */
    public static function renderSlot(string $block, array $data): string
    {
        return Slots::render($block, $data);
    }

    private static function store(): ArtifactStore
    {
        return ArtifactStoreFactory::create(\Nucleo\Storage\ArtifactStoreFactory::base());
    }

    private static function handle(string $url): string
    {
        return 'nucleo-' . substr(md5($url), 0, 8);
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * Voci lazy per il loader. Pagina e layout le aggiungono in coda: concat, non assegnazione.
     * @param list<array{type:string,src:string,strategy:string}> $entries
     */
    public static function queueLazy(array $entries): void
    {
        if ($entries === []) {
            return;
        }
        // Il loader crea i tag script da sé: la versione del plugin va nell'URL, come per gli script accodati.
        $entries = array_map(static fn (array $entry): array => ['src' => self::versioned($entry['src'])] + $entry, $entries);
        wp_enqueue_script(
            self::LOADER_HANDLE,
            plugins_url('assets/js/loader.js', NUCLEO_FILE),
            [],
            NUCLEO_VERSION,
            ['strategy' => 'defer', 'in_footer' => true],
        );
        wp_add_inline_script(
            self::LOADER_HANDLE,
            'window.ncLazy = (window.ncLazy || []).concat(' . (string) wp_json_encode($entries, JSON_HEX_TAG | JSON_HEX_AMP) . ');',
            'before',
        );
    }

    /** URL di un file del plugin con ?ver= della versione: dopo un aggiornamento il browser non usa la copia in cache. */
    public static function versioned(string $url): string
    {
        // Solo il parametro ver: ?server= o ?cover= non sono una versione.
        return $url === '' || preg_match('/[?&]ver=/', $url) === 1 ? $url : add_query_arg('ver', NUCLEO_VERSION, $url);
    }

    /** @return list<array{type:string,src:string,strategy:string}> */
    public static function lazyEntries(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = $entry['type'] ?? null;
            $src = $entry['src'] ?? null;
            $strategy = $entry['strategy'] ?? null;
            if (is_string($type) && is_string($src) && in_array($strategy, self::LAZY_STRATEGIES, true)) {
                $out[] = ['type' => $type, 'src' => $src, 'strategy' => $strategy];
            }
        }
        return $out;
    }

    /** Il documento della pagina ha uno slot del blocco con il valore di data.mode indicato (es. woo/cart, nucleo-state). */
    public static function hasSlot(string $block, string $mode): bool
    {
        foreach (self::$slots as $slot) {
            if ($slot['block'] === $block && ($slot['data']['mode'] ?? null) === $mode) {
                return true;
            }
        }

        return false;
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
}
