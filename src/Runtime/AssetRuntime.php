<?php

declare(strict_types=1);

namespace Nucleo\Runtime;

defined('ABSPATH') || exit;

use Nucleo\Assets\CriticalCss;
use Nucleo\Assets\LocalFile;
use Nucleo\Assets\RulePlanner;
use Nucleo\Storage\ArtifactStore;
use Nucleo\Storage\ArtifactStoreFactory;

/**
 * Applica le regole asset alla coda di stili e script della pagina, in wp_enqueue_scripts a priorità alta.
 */
final class AssetRuntime
{
    private const FULL_CSS_HANDLE = 'nucleo-full-css';
    private const TOKENS_HANDLE = 'nucleo-tokens';
    private const LOADER_HANDLE = 'nucleo-loader';
    /** Primo gesto dell'utente: da qui si caricano i CSS completi. */
    private const INTERACTION_EVENTS = ['pointerdown', 'keydown', 'scroll'];
    /** Duplicato di CssPreview::PARAM: senza parametro la classe CssPreview non si carica. */
    private const PREVIEW_PARAM = 'nucleo_css';

    /** @var array<string, string> handle => strategia lazy ('interaction' o 'idle') */
    private static array $lazy = [];

    /** @var list<string> CSS originali sostituiti dalle varianti ridotte, da caricare al primo gesto */
    private static array $fullCss = [];

    /** @var list<string|array{href: string, media: string}> fogli locali affidati al loader; con media diverso da "all" un oggetto */
    private static array $loaderUrls = [];

    public static function apply(): void
    {
        self::$loaderUrls = [];
        // Sul frontend normale la classe CssPreview non si carica: il parametro c'è solo nelle anteprime.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo presenza del parametro; CssPreview::mode() verifica manage_options.
        $mode = isset($_GET[self::PREVIEW_PARAM]) ? CssPreview::mode() : null;
        $artifact = self::store()->get('asset-rules');
        if ($artifact !== null) {
            $table = is_array($artifact['table'] ?? null) ? $artifact['table'] : [];
            $template = TemplateKey::current() ?? '';
            $objectKey = TemplateKey::objectKey() ?? '';
            $purged = match ($mode) {
                // Originale e critical: nessuna variante ridotte, né quelle pubblicate né quelle candidate.
                'original', 'critical' => [],
                'candidate' => [$template => self::candidateEntry($template)['purged'] ?? []],
                default => is_array($artifact['purged'] ?? null) ? $artifact['purged'] : [],
            };

            self::$lazy = [];
            self::applyStyles($template, $objectKey, $table, $purged, $mode);
            self::applyScripts($template, $objectKey, $table);
        }

        // Dopo le regole: il loader del CSS critico riceve già le varianti ridotte.
        self::applyCritical($mode);
    }

    /**
     * Critical CSS inline su nucleo-tokens. Con il normale critical pubblicato i fogli locali vanno al loader,
     * che li carica senza bloccare il rendering. Nell'anteprima critical niente fogli locali e niente loader.
     */
    private static function applyCritical(?string $mode): void
    {
        if ($mode === 'original') {
            return;
        }
        $template = TemplateKey::current();
        if ($template === null) {
            return;
        }
        $css = self::criticalFor($mode, $template);
        if ($css === null) {
            return;
        }

        $styles = wp_styles();
        if (!isset($styles->registered[self::TOKENS_HANDLE])) {
            wp_register_style(self::TOKENS_HANDLE, false, [], NUCLEO_VERSION);
            wp_enqueue_style(self::TOKENS_HANDLE);
        }
        wp_add_inline_style(self::TOKENS_HANDLE, $css);

        if ($mode === 'critical') {
            // Anche i fogli stampati tardi (blocchi WooCommerce) restano fuori.
            self::takeLocalSheets();
            $late = static function (): void {
                self::takeLocalSheets();
            };
            add_action('wp_print_styles', $late, 1);
            add_action('wp_print_footer_scripts', $late, 1);

            return;
        }

        self::$loaderUrls = self::takeLocalSheets();
        if (self::$loaderUrls !== []) {
            self::publishLoader();
        }

        // Fogli accodati o stampati dopo apply (es. blocchi WooCommerce): stessi passaggi tardivi delle varianti.
        // Vanno tolti dalla coda e affidati al loader, altrimenti restano <link> bloccanti.
        $late = static function (): void {
            $more = self::takeLocalSheets();
            if ($more === []) {
                return;
            }
            self::$loaderUrls = [...self::$loaderUrls, ...$more];
            self::publishLoader();
        };
        add_action('wp_print_styles', $late, 1);
        add_action('wp_print_footer_scripts', $late, 1);
    }

    /**
     * Il loader riceve sempre l'elenco completo: la sua riga 'window.nucleoCss' viene riscritta, non accodata.
     * Le altre righe 'before' del loader (es. window.nucleoLazy) restano.
     */
    private static function publishLoader(): void
    {
        // Stessa registrazione di Frontend::boot: enqueue registra lo script se manca.
        wp_enqueue_script(
            self::LOADER_HANDLE,
            plugins_url('assets/js/loader.js', NUCLEO_FILE),
            [],
            NUCLEO_VERSION,
            ['strategy' => 'defer', 'in_footer' => true],
        );
        $json = (string) wp_json_encode(self::$loaderUrls, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $before = array_values(array_filter(
            (array) wp_scripts()->get_data(self::LOADER_HANDLE, 'before'),
            static fn (mixed $line): bool => is_string($line) && !str_starts_with($line, 'window.nucleoCss='),
        ));
        $before[] = 'window.nucleoCss=' . $json . ';';
        wp_scripts()->add_data(self::LOADER_HANDLE, 'before', $before);
    }

    /**
     * Fogli in coda con le loro dipendenze, nell'ordine di stampa: ogni dipendenza prima del foglio che la usa.
     * Le dipendenze non sono nella coda: WordPress le aggiunge solo in stampa.
     *
     * @return list<string>
     */
    private static function printOrder(\WP_Styles $styles): array
    {
        $order = [];
        $visit = static function (string $handle) use (&$visit, &$order, $styles): void {
            if (in_array($handle, $order, true) || !isset($styles->registered[$handle])) {
                return;
            }
            $order[] = $handle; // segnato prima della visita: protegge dai cicli
            $at = count($order) - 1;
            foreach ($styles->registered[$handle]->deps as $dep) {
                if (is_string($dep)) {
                    $visit($dep);
                }
            }
            // Il foglio va dopo le sue dipendenze.
            array_splice($order, $at, 1);
            $order[] = $handle;
        };
        foreach ($styles->queue as $handle) {
            if (is_string($handle)) {
                $visit($handle);
            }
        }

        return $order;
    }

    /**
     * Toglie dalla coda i fogli con file locale e ne restituisce gli src, dipendenze comprese, nell'ordine di stampa.
     *
     * @return list<string|array{href: string, media: string}>
     */
    private static function takeLocalSheets(): array
    {
        $styles = wp_styles();
        $urls = [];
        foreach (self::printOrder($styles) as $handle) {
            $item = $styles->registered[$handle] ?? null;
            if ($handle === self::TOKENS_HANDLE || in_array($handle, $styles->done, true) || $item === null || !is_string($item->src) || $item->src === '' || LocalFile::path($item->src) === null) {
                continue;
            }
            // Il media del foglio resta: un foglio solo per schermi piccoli non deve valere su desktop.
            $media = is_string($item->args) ? trim($item->args) : '';
            $urls[] = $media === '' || $media === 'all' ? $item->src : ['href' => $item->src, 'media' => $media];
            wp_dequeue_style($handle);
            // Segnato come stampato: un foglio che dipende da questo non lo riporta nella pagina.
            $styles->done[] = $handle;
            // Lo stile inline del foglio (wp_add_inline_style) resta nella pagina con un handle senza file.
            $inline = $styles->get_data($handle, 'after');
            if (is_array($inline) && $inline !== []) {
                $keep = 'nucleo-inline-' . $handle;
                wp_register_style($keep, false, [], NUCLEO_VERSION);
                $styles->add_data($keep, 'after', $inline);
                wp_enqueue_style($keep);
            }
        }

        return $urls;
    }

    /**
     * Critical da inline: quello del candidato nelle anteprime, quello pubblicato altrimenti.
     */
    private static function criticalFor(?string $mode, string $template): ?string
    {

        // CriticalCss si carica solo se esiste un critical salvato: un file in meno sulle richieste comuni.
        return self::store()->get('critical') === null ? null : CriticalCss::get($template);
    }

    /**
     * Voce del candidato per il template (varianti ridotte e critical dell'anteprima).
     *
     * @return array<string, mixed>
     */
    private static function candidateEntry(string $template): array
    {
        // Valore di CssPreview::ARTIFACT, scritto qui per non caricare la classe sul frontend normale.
        $artifact = self::store()->get('css-candidate') ?? [];
        $entry = $artifact[$template] ?? null;

        return is_array($entry) ? $entry : [];
    }

    /**
     * @param array<string, mixed> $table
     * @param array<string, mixed> $purged
     */
    private static function applyStyles(string $template, string $objectKey, array $table, array $purged, ?string $mode): void
    {
        $styles = wp_styles();
        $plan = RulePlanner::plan($template, $objectKey, array_values($styles->queue), self::depsOf($styles), $table);
        foreach ($plan['dequeue'] as $handle) {
            wp_dequeue_style($handle);
        }
        foreach ($plan['replace'] as $handle => $with) {
            wp_dequeue_style($handle);
            if ($with !== '' && isset($styles->registered[$with])) {
                wp_enqueue_style($with);
            }
        }

        self::$fullCss = [];
        self::servePurged($template, $purged, $mode);
        // Altri passaggi prima della stampa: alcuni stili (blocchi WooCommerce) arrivano dopo wp_enqueue_scripts
        // e finiscono nell'head o nel footer (print_late_styles).
        if (is_array($purged[$template] ?? null) && $purged[$template] !== []) {
            $late = static function () use ($template, $purged, $mode): void {
                self::servePurged($template, $purged, $mode);
            };
            add_action('wp_print_styles', $late, 1);
            add_action('wp_print_footer_scripts', $late, 1);
        }
    }

    /** @param array<string, mixed> $purged */
    private static function servePurged(string $template, array $purged, ?string $mode): void
    {
        $byTemplate = is_array($purged[$template] ?? null) ? $purged[$template] : [];
        $styles = wp_styles();
        $swapped = false;
        foreach ($styles->queue as $handle) {
            $url = $byTemplate[$handle] ?? null;
            $item = $styles->registered[$handle] ?? null;
            if (!is_string($url) || $item === null || !is_string($item->src) || $item->src === '' || $item->src === $url || !self::isUploaded($url)) {
                continue;
            }
            self::$fullCss[] = $item->src;
            $item->src = $url;
            $swapped = true;
        }

        // Opzioni in autoload, già in memoria: get_option su un'opzione assente farebbe una query a ogni pagina.
        // Nelle anteprime niente CSS completi: si misura il candidato da solo.
        if ($swapped && $mode === null && (wp_load_alloptions()['nucleo_purge_mode'] ?? '') !== 'strict') {
            self::enqueueFullCss(self::$fullCss);
        }
    }

    /**
     * Il CSS ridotto viene servito solo se è un file della cartella uploads.
     */
    private static function isUploaded(string $url): bool
    {
        $path = LocalFile::path($url);
        if ($path === null) {
            return false;
        }
        $uploads = wp_upload_dir(null, false);
        $base = realpath((string) $uploads['basedir']);

        return $base !== false && str_starts_with($path, $base . DIRECTORY_SEPARATOR);
    }

    /**
     * Dopo il primo gesto dell'utente aggiunge i link ai CSS originali.
     *
     * @param list<string> $urls
     */
    private static function enqueueFullCss(array $urls): void
    {
        wp_register_script(self::FULL_CSS_HANDLE, false, [], NUCLEO_VERSION, true);
        wp_enqueue_script(self::FULL_CSS_HANDLE);

        $json = (string) wp_json_encode($urls, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $events = (string) wp_json_encode(self::INTERACTION_EVENTS);
        $js = '(function(){var urls=' . $json . ',ev=' . $events . ',done=false;'
            . 'function load(){if(done){return;}done=true;'
            . 'ev.forEach(function(e){window.removeEventListener(e,load);});'
            . 'urls.forEach(function(u){var l=document.createElement("link");l.rel="stylesheet";l.href=u;document.head.appendChild(l);});}'
            . 'ev.forEach(function(e){window.addEventListener(e,load,{passive:true});});})();';
        // add_data sostituisce: a ogni passaggio lo script contiene l'elenco completo, una volta sola.
        wp_scripts()->add_data(self::FULL_CSS_HANDLE, 'after', [$js]);
    }

    /**
     * @param array<string, mixed> $table
     */
    private static function applyScripts(string $template, string $objectKey, array $table): void
    {
        $scripts = wp_scripts();
        $plan = RulePlanner::plan($template, $objectKey, array_values($scripts->queue), self::depsOf($scripts), $table);
        foreach ($plan['dequeue'] as $handle) {
            wp_dequeue_script($handle);
        }
        foreach ($plan['replace'] as $handle => $with) {
            wp_dequeue_script($handle);
            if ($with !== '' && isset($scripts->registered[$with])) {
                wp_enqueue_script($with);
            }
        }
        $deps = self::depsOf($scripts);
        foreach ($plan['defer'] as $handle => $strategy) {
            if (($strategy === 'interaction' || $strategy === 'idle') && self::canDelay($scripts, $handle, $deps)) {
                // Il tag viene caricato in differita e il loader esistente lo attiva con data-nc-load.
                wp_script_add_data($handle, 'strategy', 'defer');
                self::$lazy[$handle] = $strategy;
                continue;
            }
            $lazy = $strategy === 'interaction' || $strategy === 'idle';
            wp_script_add_data($handle, 'strategy', $lazy ? 'defer' : $strategy);
        }

        if (self::$lazy !== []) {
            add_filter('script_loader_tag', [self::class, 'tagLazy'], 10, 2);
            // Senza il loader i tag inerti non partono mai: lo accoda anche su una pagina senza blocchi lazy.
            wp_enqueue_script(
                self::LOADER_HANDLE,
                plugins_url('assets/js/loader.js', NUCLEO_FILE),
                [],
                NUCLEO_VERSION,
                ['strategy' => 'defer', 'in_footer' => true],
            );
        }
    }

    /**
     * Uno script si ritarda al gesto solo se nessuno script in coda dipende da lui e non ha script inline:
     * altrimenti chi ne dipende e il codice inline girerebbero prima della libreria. In quel caso resta defer.
     *
     * @param array<string,list<string>> $deps handle in coda => dipendenze
     */
    private static function canDelay(\WP_Scripts $scripts, string $handle, array $deps): bool
    {
        foreach ($deps as $other => $list) {
            if ($other !== $handle && in_array($handle, $list, true)) {
                return false;
            }
        }
        foreach (['before', 'after'] as $position) {
            $inline = $scripts->get_data($handle, $position);
            if (is_array($inline) && array_filter($inline, static fn (mixed $line): bool => is_string($line) && trim($line) !== '') !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Filtro script_loader_tag: gli script con strategia lazy non hanno più src ma type="text/plain" e data-nc-src,
     * con data-nc-load per la strategia; il loader li attiva al primo gesto o in idle.
     */
    public static function tagLazy(string $tag, string $handle): string
    {
        $strategy = self::$lazy[$handle] ?? null;
        if ($strategy === null) {
            return $tag;
        }

        return (string) preg_replace(
            '/\ssrc=(["\'])(.*?)\1/',
            ' type="text/plain" data-nc-src="$2" data-nc-load="' . esc_attr($strategy) . '"',
            $tag,
            1,
        );
    }

    /**
     * @return array<string, list<string>> handle => handle da cui dipende
     */
    private static function depsOf(\WP_Dependencies $dependencies): array
    {
        $map = [];
        foreach ($dependencies->queue as $handle) {
            $item = $dependencies->registered[$handle] ?? null;
            $map[$handle] = $item !== null ? array_values($item->deps) : [];
        }

        return $map;
    }

    /** Store degli artefatti senza il contenitore Services: sul frontend conta ogni file incluso. */
    private static function store(): ArtifactStore
    {
        return ArtifactStoreFactory::create(\Nucleo\Storage\ArtifactStoreFactory::base());
    }
}
