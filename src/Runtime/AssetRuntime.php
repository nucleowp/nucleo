<?php

declare(strict_types=1);

namespace Nucleo\Runtime;

defined('ABSPATH') || exit;

use Nucleo\Assets\CriticalCss;
use Nucleo\Assets\LocalFile;
use Nucleo\Assets\RulePlanner;
use Nucleo\Assets\ScriptPolicy;
use Nucleo\Assets\SourceRules;
use Nucleo\Compiler\CssFiles;
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
    /** Suffisso dell'id di un tag modulo: l'handle è l'id senza suffisso. */
    private const MODULE_SUFFIX = '-js-module';
    /** Primo gesto dell'utente: da qui si caricano i CSS completi. */
    private const INTERACTION_EVENTS = ['pointerdown', 'keydown', 'scroll'];
    /** Duplicato di CssPreview::PARAM: senza parametro la classe CssPreview non si carica. */
    private const PREVIEW_PARAM = 'nucleo_css';
    /** Con il critical attivo il CSS di un documento va inline fino a questa misura (50 KB); oltre resta un <link>. */
    private const DOCUMENT_INLINE_MAX = 51200;

    /** @var array<string, string> handle => strategia lazy ('interaction' o 'idle') */
    private static array $lazy = [];

    /** @var array<string, string> handle => selettore CSS (data-nc-replay) della regola defer che lo riguarda */
    private static array $replay = [];

    /** @var list<string> CSS originali sostituiti dalle varianti ridotte, da caricare al primo gesto */
    private static array $fullCss = [];

    /** @var list<string|array{href: string, media?: string, after?: string}> fogli locali affidati al loader; un oggetto se hanno un media proprio o un punto di inserimento */
    private static array $loaderUrls = [];

    /** Id dell'ultimo CSS di documento lasciato nella pagina: i fogli rimandati che lo seguono tornano dopo di lui. */
    private static ?string $documentAnchor = null;

    /** @var array<string, array{action: string, applied: bool, reason: string, late: bool}> */
    private static array $outcomes = [];

    /** @var list<string> coda degli script al primo passaggio (wp_enqueue_scripts 9999) */
    private static array $early = [];

    /** @var array<string, true> handle degli script già visti da un passaggio degli script */
    private static array $seen = [];

    /** @var list<string> shortcode e blocchi in uso nella richiesta */
    private static array $used = [];

    /** @var array<string, array{type: string, uses: list<string>}> handle tolti da una regola con uses: tornano se il token compare in seguito */
    private static array $byUses = [];

    /** @var array<string, mixed>|null artefatto asset-rules, letto una sola volta per richiesta */
    private static ?array $artifact = null;
    private static bool $artifactRead = false;

    /** Contesto degli script per i passaggi tardivi: template, chiave d'oggetto e tabella della pagina. */
    private static string $template = '';
    private static string $objectKey = '';
    /** @var array<string, mixed> */
    private static array $table = [];

    /**
     * Cosa ha fatto il runtime per ogni script con una regola, in questa richiesta.
     * action: dequeue, replace, keep, defer, async, interaction, idle. applied false con il motivo in reason:
     * un dequeue fermato da un dipendente ('dependents') o un ritardo al gesto declassato a defer (motivo di ScriptPolicy).
     * late: deciso in un passaggio dopo il primo.
     *
     * @return array<string, array{action: string, applied: bool, reason: string, late: bool}>
     */
    public static function outcomes(): array
    {
        return self::$outcomes;
    }

    /** @return list<string> */
    public static function early(): array
    {
        return self::$early;
    }

    /**
     * Segna in uso uno shortcode o un blocco (nome con /) visto durante il rendering.
     * Le regole con uses che lo citano non si applicano; gli handle già rimossi da quelle regole tornano in coda.
     */
    public static function noteUse(string $token): void
    {
        if ($token === '') {
            return;
        }
        if (!in_array($token, self::$used, true)) {
            self::$used[] = $token;
        }
        foreach (self::$byUses as $key => $entry) {
            if (!in_array($token, $entry['uses'], true)) {
                continue;
            }
            $handle = (string) $key;
            unset(self::$byUses[$key]);
            if ($handle === '') {
                continue;
            }
            if ($entry['type'] === 'style') {
                wp_enqueue_style($handle);
            } else {
                wp_enqueue_script($handle);
            }
        }
    }

    /**
     * Registra i filtri dei token in uso solo se la tabella delle regole cita almeno un token in uses.
     * Senza artefatto o senza uses nessun filtro: le pagine senza regole con uses non pagano il rendering.
     */
    public static function watchUses(): void
    {
        // Sempre una lettura nuova: una per richiesta, e apply() la riusa.
        self::$artifact = self::store()->get('asset-rules');
        self::$artifactRead = true;
        $table = is_array(self::$artifact['table'] ?? null) ? self::$artifact['table'] : [];
        if (self::usesOf($table) === []) {
            return;
        }
        if (has_filter('pre_do_shortcode_tag', [self::class, 'noteShortcode']) === false) {
            add_filter('pre_do_shortcode_tag', [self::class, 'noteShortcode'], 10, 2);
        }
        if (has_filter('pre_render_block', [self::class, 'noteBlock']) === false) {
            add_filter('pre_render_block', [self::class, 'noteBlock'], 10, 2);
        }
    }

    /**
     * Filtro pre_do_shortcode_tag: il rendering non cambia, il primo argomento torna com'è.
     *
     * @internal
     */
    public static function noteShortcode(mixed $return, string $tag): mixed
    {
        self::noteUse($tag);

        return $return;
    }

    /**
     * Filtro pre_render_block: il rendering non cambia, il primo argomento torna com'è.
     *
     * @param array<string, mixed> $block
     * @internal
     */
    public static function noteBlock(mixed $return, array $block): mixed
    {
        self::noteUse(is_string($block['blockName'] ?? null) ? $block['blockName'] : '');

        return $return;
    }

    /**
     * Artefatto asset-rules: se watchUses() l'ha già letto, lo consegna e lo consuma;
     * una chiamata successiva di apply() nella stessa richiesta rilegge lo store.
     *
     * @return array<string, mixed>|null
     */
    private static function artifact(): ?array
    {
        if (!self::$artifactRead) {
            return self::store()->get('asset-rules');
        }
        $artifact = self::$artifact;
        self::$artifact = null;
        self::$artifactRead = false;

        return $artifact;
    }

    /**
     * Regole sugli script fuori coda che valgono per la pagina (SourceRules::applicable). Legge l'artefatto come
     * watchUses, senza consumarlo: apply() lo riusa. Senza template nessuna regola.
     *
     * @return list<array<string, mixed>>
     * @internal
     */
    public static function sources(): array
    {
        $template = TemplateKey::current();
        if ($template === null || $template === '') {
            return [];
        }
        if (!self::$artifactRead) {
            self::$artifact = self::store()->get('asset-rules');
            self::$artifactRead = true;
        }
        $sources = is_array(self::$artifact['sources'] ?? null) ? self::$artifact['sources'] : [];
        // Senza regole sorgente SourceRules non si carica: il budget dei file per richiesta resta quello.
        if ($sources === []) {
            return [];
        }

        return SourceRules::applicable($sources, $template, TemplateKey::objectKey() ?? '');
    }

    /**
     * Azzera lo stato della richiesta. Solo per i test.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$lazy = [];
        self::$replay = [];
        self::$fullCss = [];
        self::$loaderUrls = [];
        self::$documentAnchor = null;
        self::$outcomes = [];
        self::$early = [];
        self::$seen = [];
        self::$used = [];
        self::$byUses = [];
        self::$template = '';
        self::$objectKey = '';
        self::$table = [];
        self::$artifact = null;
        self::$artifactRead = false;
    }

    public static function apply(): void
    {
        self::$loaderUrls = [];
        self::$documentAnchor = null;
        // Sul frontend normale la classe CssPreview non si carica: il parametro c'è solo nelle anteprime.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo presenza del parametro; CssPreview::mode() verifica manage_options.
        $mode = isset($_GET[self::PREVIEW_PARAM]) ? CssPreview::mode() : null;
        // Anteprima JS (solo pacchetto Pro): la classe si carica solo se il parametro nucleo_js è nella richiesta.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo presenza del parametro; JsPreview::mode() verifica manage_options o il token.
        $jsMode = isset($_GET['nucleo_js']) ? \Nucleo\Support\Optional::call('Nucleo\\Runtime\\JsPreview', 'mode') : null;
        $jsMode = is_string($jsMode) ? $jsMode : null;
        $artifact = self::artifact();
        $template = TemplateKey::current() ?? '';
        $objectKey = TemplateKey::objectKey() ?? '';
        $table = is_array($artifact['table'] ?? null) ? $artifact['table'] : [];
        $withDelays = self::withJsDelays($table, $template, $objectKey, $jsMode, $artifact);
        // Senza artefatto ci sono solo i ritardi JS: nessuna regola di stili e nessuna riduzione.
        if ($artifact !== null || $withDelays !== $table) {
            $table = $withDelays;
            $purged = match ($mode) {
                // Originale e critical: nessuna variante ridotte, né quelle pubblicate né quelle candidate.
                'original', 'critical' => [],
                'candidate' => [$template => self::candidateEntry($template)['purged'] ?? []],
                default => is_array($artifact['purged'] ?? null) ? $artifact['purged'] : [],
            };
            $purged = $artifact === null ? [] : $purged;

            self::$lazy = [];
            self::noteContent($table);
            self::applyStyles($template, $objectKey, $table, $purged, $mode);
            self::applyScripts($template, $objectKey, $table);
        }

        // Dopo le regole: il loader del CSS critico riceve già le varianti ridotte.
        self::applyCritical($mode);
    }

    /**
     * Ritardi al gesto degli script JS del template, aggiunti alla tabella come regole defer interaction con force.
     * Anteprima candidate: gli handle di JsPreview::delay(), senza replay. Anteprima original: nessuno.
     * Altrimenti quelli pubblicati (handle e replay). Nessuno su carrello e cassa, né con template vuoto.
     * Una regola già presente per l'handle (oggetto, template o '*') vince.
     *
     * @param array<string, mixed> $table
     * @param array<string, mixed>|null $artifact
     * @return array<string, mixed>
     */
    private static function withJsDelays(array $table, string $template, string $objectKey, ?string $jsMode, ?array $artifact): array
    {
        if ($template === '' || $jsMode === 'original' || (function_exists('is_cart') && (is_cart() || is_checkout()))) {
            return $table;
        }
        if ($jsMode === 'candidate') {
            $handles = \Nucleo\Support\Optional::call('Nucleo\\Runtime\\JsPreview', 'delay');
            $handles = is_array($handles) ? array_filter($handles, 'is_string') : [];
            $replay = null;
        } else {
            $entry = $artifact['delayed'][$template] ?? null;
            $handles = is_array($entry) && is_array($entry['handles'] ?? null) ? array_filter($entry['handles'], 'is_string') : [];
            $replay = is_array($entry) && is_string($entry['replay'] ?? null) ? $entry['replay'] : null;
        }
        // Una voce vale per la pagina se è un array e il suo except non contiene né il template né l'oggetto (come RulePlanner).
        $applies = static fn(mixed $rule): bool => is_array($rule)
            && !in_array($template, (array) ($rule['except'] ?? []), true)
            && ($objectKey === '' || !in_array($objectKey, (array) ($rule['except'] ?? []), true));
        foreach ($handles as $handle) {
            $keys = $objectKey === '' ? [$template, '*'] : [$objectKey, $template, '*'];
            $covered = false;
            foreach ($keys as $key) {
                if ($applies($table[$key][$handle] ?? null)) {
                    $covered = true;
                    break;
                }
            }
            if ($covered) {
                continue;
            }
            $rule = ['action' => 'defer', 'except' => [], 'strategy' => 'interaction', 'force' => true, 'group' => true];
            if ($replay !== null) {
                $rule['replay'] = $replay;
            }
            if (!isset($table[$template][$handle])) {
                $table[$template][$handle] = $rule;
            } elseif ($objectKey !== '') {
                // Sul template c'è una voce che non vale per questa pagina: la regola va sull'oggetto.
                $table[$objectKey][$handle] = $rule;
            }
        }

        return $table;
    }

    /**
     * Critical CSS inline su nucleo-tokens. Con il normale critical pubblicato i fogli locali vanno al loader,
     * che li carica senza bloccare il rendering. Nell'anteprima critical niente fogli locali e niente loader.
     * Il CSS compilato dei documenti resta nella pagina, inline: il critical vale per tipo di template
     * e non contiene le regole di una singola pagina.
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
     * Restano nella pagina i fogli esterni e il CSS compilato dei documenti, stampato inline. Un foglio rimandato
     * che segue il CSS di un documento porta "after" con l'id di quell'elemento.
     *
     * @return list<string|array{href: string, media?: string, after?: string}>
     */
    private static function takeLocalSheets(): array
    {
        $styles = wp_styles();
        $urls = [];
        // Nei passaggi tardivi i fogli nuovi seguono i documenti già stampati.
        $anchor = self::$documentAnchor;
        foreach (self::printOrder($styles) as $handle) {
            $item = $styles->registered[$handle] ?? null;
            if ($handle === self::TOKENS_HANDLE || $item === null || !is_string($item->src) || $item->src === '') {
                continue;
            }
            $path = LocalFile::path($item->src);
            if ($path === null) {
                continue;
            }
            if (CssFiles::isDocumentCss($path)) {
                // Resta nella pagina: i fogli rimandati che lo seguono tornano dopo di lui, come nella pagina originale.
                $inlined = !in_array($handle, $styles->done, true) && self::inlineDocumentCss($styles, $handle, $path);
                $anchor = self::$documentAnchor = $inlined ? 'nucleo-inline-' . $handle . '-inline-css' : $handle . '-css';
                continue;
            }
            if (in_array($handle, $styles->done, true)) {
                continue;
            }
            // Il media del foglio resta: un foglio solo per schermi piccoli non deve valere su desktop.
            $media = is_string($item->args) ? trim($item->args) : '';
            $entry = ['href' => $item->src];
            if ($media !== '' && $media !== 'all') {
                $entry['media'] = $media;
            }
            if ($anchor !== null) {
                $entry['after'] = $anchor;
            }
            $urls[] = count($entry) === 1 ? $item->src : $entry;
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
     * Stampa inline il CSS compilato di un documento al posto del suo <link>, nella stessa posizione della coda:
     * un <link> bloccante costa una richiesta prima del primo rendering e annulla il guadagno del critical.
     * Oltre DOCUMENT_INLINE_MAX il foglio resta un <link>.
     */
    private static function inlineDocumentCss(\WP_Styles $styles, string $handle, string $path): bool
    {
        $size = filesize($path);
        $css = $size !== false && $size <= self::DOCUMENT_INLINE_MAX ? file_get_contents($path) : false;
        if ($css === false) {
            return false;
        }
        // Il compilatore non scrive url() nel CSS dei documenti: niente percorsi relativi da correggere.
        // "</style" chiuderebbe il tag inline.
        $css = (string) preg_replace('#</style#i', '', $css);
        $after = $styles->get_data($handle, 'after');

        $keep = 'nucleo-inline-' . $handle;
        wp_register_style($keep, false, [], NUCLEO_VERSION);
        $styles->add_data($keep, 'after', [$css, ...(is_array($after) ? array_values($after) : [])]);
        // Stessa posizione nella coda: l'ordine con gli stili che restano non cambia.
        $at = array_search($handle, $styles->queue, true);
        if ($at === false) {
            wp_enqueue_style($keep);
        } else {
            $styles->queue[$at] = $keep;
        }
        // Segnato come stampato: un foglio che dipende da questo non lo riporta nella pagina.
        $styles->done[] = $handle;

        return true;
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
     * Token in uso dal contenuto della pagina: uno shortcode '[token' o un blocco '<!-- wp:token '.
     * Solo i token citati da una regola con uses.
     *
     * @param array<string, mixed> $table
     */
    private static function noteContent(array $table): void
    {
        if (!is_singular()) {
            return;
        }
        $content = (string) get_post_field('post_content', get_queried_object_id());
        foreach (self::usesOf($table) as $token) {
            if (str_contains($content, '[' . $token) || str_contains($content, '<!-- wp:' . $token . ' ')) {
                if (!in_array($token, self::$used, true)) {
                    self::$used[] = $token;
                }
            }
        }
    }

    /**
     * Tutti i token citati dalle regole con uses della tabella.
     *
     * @param array<string, mixed> $table
     * @return list<string>
     */
    private static function usesOf(array $table): array
    {
        $tokens = [];
        foreach ($table as $rules) {
            if (!is_array($rules)) {
                continue;
            }
            foreach ($rules as $rule) {
                foreach (is_array($rule['uses'] ?? null) ? $rule['uses'] : [] as $token) {
                    if (is_string($token) && $token !== '' && !in_array($token, $tokens, true)) {
                        $tokens[] = $token;
                    }
                }
            }
        }

        return $tokens;
    }

    /**
     * @param array<string, mixed> $table
     * @param array<string, mixed> $purged
     */
    private static function applyStyles(string $template, string $objectKey, array $table, array $purged, ?string $mode): void
    {
        $styles = wp_styles();
        $plan = RulePlanner::plan($template, $objectKey, array_values($styles->queue), self::depsOf($styles), $table, self::$used);
        foreach ($plan['dequeue'] as $handle) {
            wp_dequeue_style($handle);
        }
        self::rememberUses($plan['uses'], 'style');
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
     * Primo passaggio sugli script (wp_enqueue_scripts 9999). I passaggi successivi, su wp_print_scripts e
     * wp_print_footer_scripts, prendono gli script accodati dopo: vedi lateScripts.
     *
     * @param array<string, mixed> $table
     */
    private static function applyScripts(string $template, string $objectKey, array $table): void
    {
        $scripts = wp_scripts();
        self::$outcomes = [];
        self::$early = array_values($scripts->queue);
        self::$seen = [];
        foreach (self::$early as $handle) {
            self::$seen[$handle] = true;
        }
        self::$template = $template;
        self::$objectKey = $objectKey;
        self::$table = $table;

        self::scriptPass(self::$early, false);
        self::enableLazy();

        // Il filtro dei moduli serve solo se la tabella ha un ritardo al gesto o in idle: altrimenti nessuna chiamata in più.
        if (self::hasLazyRule($table) && has_filter('wp_script_attributes', [self::class, 'moduleAttributes']) === false) {
            add_filter('wp_script_attributes', [self::class, 'moduleAttributes']);
        }

        if (!has_action('wp_print_scripts', [self::class, 'lateScripts'])) {
            add_action('wp_print_scripts', [self::class, 'lateScripts'], 1);
        }
        if (!has_action('wp_print_footer_scripts', [self::class, 'lateScripts'])) {
            add_action('wp_print_footer_scripts', [self::class, 'lateScripts'], 1);
        }
    }

    /**
     * Script accodati dopo il primo passaggio (blocchi, plugin tardivi): solo quelli che nessun passaggio precedente
     * ha visto e che non sono già stampati. Regole con uses non si applicano qui.
     *
     * @internal
     */
    public static function lateScripts(): void
    {
        $scripts = wp_scripts();
        $fresh = [];
        foreach ($scripts->queue as $handle) {
            if (!is_string($handle) || isset(self::$seen[$handle])) {
                continue;
            }
            self::$seen[$handle] = true;
            if (!in_array($handle, $scripts->done, true)) {
                $fresh[] = $handle;
            }
        }
        if ($fresh === []) {
            return;
        }

        self::scriptPass($fresh, true);
        if (self::$lazy !== []) {
            self::joinLazyDependents($fresh);
        }
        self::enableLazy();
    }

    /**
     * Un passaggio sugli script dati: dequeue, replace, keep e defer secondo la tabella.
     * Le dipendenze non in coda e non ancora stampate passano da un secondo piano, solo per i ritardi al gesto o in idle.
     *
     * @param list<string> $handles
     */
    private static function scriptPass(array $handles, bool $late): void
    {
        $scripts = wp_scripts();
        $deps = self::depsOf($scripts);
        $plan = RulePlanner::plan(self::$template, self::$objectKey, $handles, $deps, self::$table, self::$used, $late);
        foreach ($plan['dequeue'] as $handle) {
            wp_dequeue_script($handle);
            // Tolto dalla coda: se un widget lo riaccoda, il passaggio successivo deve rivederlo.
            unset(self::$seen[$handle]);
            self::outcome($handle, 'dequeue', true, '', $late);
        }
        foreach ($plan['blocked'] as $handle) {
            self::outcome($handle, 'dequeue', false, ScriptPolicy::DEPENDENTS, $late);
        }
        foreach ($plan['replace'] as $handle => $with) {
            wp_dequeue_script($handle);
            unset(self::$seen[$handle]);
            if ($with !== '' && isset($scripts->registered[$with])) {
                wp_enqueue_script($with);
            }
            self::outcome($handle, 'replace', true, '', $late);
        }
        foreach ($plan['keep'] as $handle) {
            self::outcome($handle, 'keep', true, '', $late);
        }
        self::rememberUses($plan['uses'], 'script');

        // Senza regole di ritardo al gesto o in idle bastano i defer: grafo e ScriptPolicy non si caricano.
        if (!self::hasLazyRule(self::$table)) {
            self::deferPass($plan['defer'], [], [], [], [], $late);

            return;
        }

        // Il grafo copre tutta la coda e le dipendenze, anche indirette: il verdetto del ritardo non dipende dal passaggio.
        $graph = self::graphOf($scripts, array_values($scripts->queue));
        $facts = self::scriptFacts($scripts, $graph);
        self::deferPass($plan['defer'], $plan['force'], $plan['group'], $plan['replay'], $facts, $late);

        // Dipendenze caricate senza essere in coda: solo i ritardi al gesto o in idle, come per la coda.
        $extra = [];
        foreach (ScriptPolicy::closure($handles, $graph) as $handle) {
            if (in_array($handle, $handles, true) || in_array($handle, $scripts->queue, true) || in_array($handle, $scripts->done, true) || isset(self::$seen[$handle])) {
                continue;
            }
            $extra[] = $handle;
            self::$seen[$handle] = true;
        }
        if ($extra === []) {
            return;
        }
        $second = RulePlanner::plan(self::$template, self::$objectKey, $extra, $graph, self::$table, self::$used, $late);
        $lazy = array_filter(
            $second['defer'],
            static fn (string $strategy): bool => $strategy === 'interaction' || $strategy === 'idle',
        );
        self::deferPass($lazy, $second['force'], $second['group'], $second['replay'], $facts, $late);
    }

    /**
     * Applica i defer di un passaggio. Il ritardo al gesto (interaction, idle) vale solo se la policy lo permette;
     * con group ritarda anche tutti i dipendenti dello script.
     *
     * @param array<string, string> $defer handle => strategia della regola
     * @param list<string> $force
     * @param list<string> $group
     * @param array<string, string> $replay handle => selettore CSS della regola
     * @param array<string, array{deps?: list<string>, inline?: bool}> $facts
     */
    private static function deferPass(array $defer, array $force, array $group, array $replay, array $facts, bool $late): void
    {
        foreach ($defer as $handle => $strategy) {
            $handle = (string) $handle;
            if ($strategy !== 'interaction' && $strategy !== 'idle') {
                wp_script_add_data($handle, 'strategy', $strategy);
                self::outcome($handle, $strategy, true, '', $late);
                continue;
            }
            $verdict = ScriptPolicy::verdict($handle, $facts, in_array($handle, $force, true), in_array($handle, $group, true));
            if (!$verdict['delay']) {
                wp_script_add_data($handle, 'strategy', 'defer');
                self::outcome($handle, $strategy, false, $verdict['reasons'][0], $late);
                continue;
            }
            // Uno script ritardato resta com'è stampato: con un defer aggiunto qui il loader lo metterebbe dopo i suoi dipendenti.
            // Il tag viene caricato in differita e il loader esistente lo attiva con data-nc-load.
            self::markLazy($handle, $strategy);
            if (isset($replay[$handle])) {
                self::$replay[$handle] = $replay[$handle];
            }
            self::outcome($handle, $strategy, true, '', $late);
            foreach ($verdict['group'] as $member) {
                if ($member === $handle) {
                    continue;
                }
                self::markLazy($member, $strategy);
                self::outcome($member, $strategy, true, 'group', $late);
            }
        }
    }

    /** Un handle già lazy resta su interaction: il gesto arriva prima dell'idle. */
    private static function markLazy(string $handle, string $strategy): void
    {
        if ((self::$lazy[$handle] ?? null) === 'interaction') {
            return;
        }
        self::$lazy[$handle] = $strategy;
    }

    /**
     * Script non ancora stampati che dipendono, anche indirettamente, da un handle lazy: li segue nel ritardo,
     * anche se protetti. Chi ha già un ritardo non cambia.
     *
     * @param list<string> $fresh
     */
    private static function joinLazyDependents(array $fresh): void
    {
        $scripts = wp_scripts();
        $graph = self::graphOf($scripts, $fresh);
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach (ScriptPolicy::closure($fresh, $graph) as $handle) {
                if (isset(self::$lazy[$handle]) || in_array($handle, $scripts->done, true)) {
                    continue;
                }
                foreach (ScriptPolicy::closure($graph[$handle] ?? [], $graph) as $dep) {
                    if (!isset(self::$lazy[$dep])) {
                        continue;
                    }
                    $strategy = self::$lazy[$dep];
                    self::$lazy[$handle] = $strategy;
                    self::outcome($handle, $strategy, true, 'group', true);
                    $changed = true;
                    break;
                }
            }
        }
    }

    /**
     * Filtro script_loader_tag e loader, una sola volta: servono appena c'è uno script lazy, anche in un passaggio tardivo.
     */
    private static function enableLazy(): void
    {
        if (self::$lazy === []) {
            return;
        }
        if (has_filter('script_loader_tag', [self::class, 'tagLazy']) === false) {
            add_filter('script_loader_tag', [self::class, 'tagLazy'], 10, 2);
        }
        self::enqueueLoader();
    }

    /**
     * Il loader accodato da solo: serve anche a un modulo lazy, che non passa da script_loader_tag.
     * Senza il loader i tag inerti non partono mai: lo accoda anche su una pagina senza blocchi lazy.
     *
     * @internal
     */
    public static function enqueueLoader(): void
    {
        wp_enqueue_script(
            self::LOADER_HANDLE,
            plugins_url('assets/js/loader.js', NUCLEO_FILE),
            [],
            NUCLEO_VERSION,
            ['strategy' => 'defer', 'in_footer' => true],
        );
    }

    /**
     * Gli handle che un passaggio ha tolto per una regola con uses: tornano con noteUse.
     *
     * @param array<string, list<string>> $uses
     */
    private static function rememberUses(array $uses, string $type): void
    {
        foreach ($uses as $handle => $tokens) {
            self::$byUses[(string) $handle] = ['type' => $type, 'uses' => $tokens];
        }
    }

    private static function outcome(string $handle, string $action, bool $applied, string $reason, bool $late): void
    {
        self::$outcomes[$handle] = ['action' => $action, 'applied' => $applied, 'reason' => $reason, 'late' => $late];
    }

    /**
     * Fatti per ScriptPolicy di ogni handle del grafo: dipendenze e presenza di codice inline.
     *
     * @param array<string, list<string>> $graph
     * @return array<string, array{deps: list<string>, inline: bool}>
     */
    private static function scriptFacts(\WP_Scripts $scripts, array $graph): array
    {
        $facts = [];
        foreach ($graph as $handle => $list) {
            $facts[(string) $handle] = ['deps' => $list, 'inline' => self::hasInline($scripts, (string) $handle)];
        }

        return $facts;
    }

    /** Codice inline non vuoto prima o dopo lo script. */
    private static function hasInline(\WP_Scripts $scripts, string $handle): bool
    {
        foreach (['before', 'after'] as $position) {
            $inline = $scripts->get_data($handle, $position);
            if (is_array($inline) && array_filter($inline, static fn (mixed $line): bool => is_string($line) && trim($line) !== '') !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filtro script_loader_tag: gli script con strategia lazy escono inerti, type="text/plain" e data-nc-load per la strategia;
     * il src passa a data-nc-src. Ogni tag dello script (anche il codice inline before e after) viene riscritto,
     * salvo i tipi diversi da JavaScript. Il loader li attiva al primo gesto o in idle.
     */
    public static function tagLazy(string $tag, string $handle): string
    {
        $strategy = self::$lazy[$handle] ?? null;
        if ($strategy === null) {
            return $tag;
        }

        $processor = new \WP_HTML_Tag_Processor($tag);
        while ($processor->next_tag(['tag_name' => 'SCRIPT'])) {
            $type = $processor->get_attribute('type');
            $kind = is_string($type) ? strtolower(trim($type)) : '';
            if ($kind !== '' && !in_array($kind, ['text/javascript', 'application/javascript', 'module'], true)) {
                continue;
            }
            $src = $processor->get_attribute('src');
            $processor->set_attribute('type', 'text/plain');
            $processor->set_attribute('data-nc-load', $strategy);
            if (is_string($src)) {
                $processor->remove_attribute('src');
                $processor->set_attribute('data-nc-src', $src);
                if (isset(self::$replay[$handle])) {
                    $processor->set_attribute('data-nc-replay', self::$replay[$handle]);
                }
            }
        }

        return $processor->get_updated_html();
    }

    /**
     * Filtro wp_script_attributes: un modulo (type module, id handle + -js-module) con regola al gesto o in idle
     * esce come tag inerte con data-nc-module. Gli altri tag restano come sono.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     * @internal
     */
    public static function moduleAttributes(array $attributes): array
    {
        $id = $attributes['id'] ?? null;
        $src = $attributes['src'] ?? null;
        if (($attributes['type'] ?? null) !== 'module' || !is_string($src) || $src === '' || !is_string($id) || !str_ends_with($id, self::MODULE_SUFFIX)) {
            return $attributes;
        }

        $handle = substr($id, 0, -strlen(self::MODULE_SUFFIX));
        $plan = RulePlanner::plan(self::$template, self::$objectKey, [$handle], [], self::$table, self::$used);
        $strategy = $plan['defer'][$handle] ?? null;
        if ($strategy !== 'interaction' && $strategy !== 'idle') {
            return $attributes;
        }

        unset($attributes['src']);
        $attributes['type'] = 'text/plain';
        $attributes['data-nc-src'] = $src;
        $attributes['data-nc-load'] = $strategy;
        $attributes['data-nc-module'] = true;
        if (isset($plan['replay'][$handle])) {
            $attributes['data-nc-replay'] = $plan['replay'][$handle];
        }
        self::outcome($handle, $strategy, true, '', true);
        self::enqueueLoader();

        return $attributes;
    }

    /**
     * Almeno una regola defer con interaction o idle nella tabella.
     *
     * @param array<string, mixed> $table
     */
    private static function hasLazyRule(array $table): bool
    {
        foreach ($table as $rules) {
            if (!is_array($rules)) {
                continue;
            }
            foreach ($rules as $rule) {
                if (($rule['action'] ?? null) === 'defer' && in_array($rule['strategy'] ?? null, ['interaction', 'idle'], true)) {
                    return true;
                }
            }
        }

        return false;
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

    /**
     * Gli handle dati più tutte le dipendenze registrate, anche indirette: handle => handle da cui dipende.
     *
     * @param list<string> $handles
     * @return array<string, list<string>>
     */
    private static function graphOf(\WP_Dependencies $dependencies, array $handles): array
    {
        $map = [];
        $visit = static function (string $handle) use (&$visit, &$map, $dependencies): void {
            if (isset($map[$handle])) {
                return;
            }
            $item = $dependencies->registered[$handle] ?? null;
            $map[$handle] = $item !== null ? array_values($item->deps) : [];
            foreach ($map[$handle] as $dep) {
                $visit($dep);
            }
        };
        foreach ($handles as $handle) {
            $visit($handle);
        }

        return $map;
    }

    /** Store degli artefatti senza il contenitore Services: sul frontend conta ogni file incluso. */
    private static function store(): ArtifactStore
    {
        return ArtifactStoreFactory::create(\Nucleo\Storage\ArtifactStoreFactory::base());
    }
}
