<?php

declare(strict_types=1);

namespace Nucleo;

use Nucleo\Content\ModelRegistrar;
use Nucleo\Runtime\Bootstrap;
use Nucleo\Seo\StructuredData;
use Nucleo\Support\Activation;
use Nucleo\Support\Context;
use Nucleo\Support\ContextDetector;
use Nucleo\Support\Requirements;

final class Plugin
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        // Pacchetti del sito e Pro: registra la cartella delle traduzioni incluse (WordPress 6.7+ carica il .mo alla prima
        // stringa tradotta). Il pacchetto per WordPress.org non la contiene: le traduzioni arrivano da translate.wordpress.org.

        // Abilities register when something reads the registry. Core always initialises
        // categories before abilities, so one hook is enough: the registrar adds the other.
        add_action('wp_abilities_api_categories_init', static function (): void {
            self::call('Nucleo\\Abilities\\AbilityRegistrar');
        });

        // REST-only modules load when WordPress starts the REST server. Priority 5: the modules add their own
        // rest_api_init callbacks at 10, which WordPress still runs; at the same priority it would skip them.
        add_action('rest_api_init', static function (): void {
            self::writers();
            self::call('Nucleo\\Admin\\Editor');
            self::call('Nucleo\\Mcp\\RestController');
            self::call('Nucleo\\OAuth\\RestRoutes');
            self::call('Nucleo\\Runtime\\Collection\\CollectionRest');
            self::call('Nucleo\\Forms\\FormRest');
        }, 5);

        // OAuth bearer tokens: only when the request carries one (zero cost otherwise).
        $auth = sanitize_text_field(wp_unslash((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')));
        if (is_string($auth) && str_starts_with($auth, 'Bearer nca_')) {
            self::call('Nucleo\\OAuth\\BearerAuth');
        }

        // /.well-known/oauth-* discovery documents.
        $uri = sanitize_text_field(wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '')));
        if (is_string($uri) && str_contains($uri, '/.well-known/oauth-')) {
            self::call('Nucleo\\OAuth\\WellKnown');
        }

        // Background recompilation: WP-Cron, or Action Scheduler's runner (WP-Cron, admin-ajax loopback, WP-CLI).
        // Never on public pages: the runtime budget counts files and callbacks there.
        if (wp_doing_cron() || is_admin() || (defined('WP_CLI') && WP_CLI)) {
            self::call('Nucleo\\Compiler\\RebuildQueue');
        }

        // Asset audit loopback requests carry a signed capture token.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- carica la classe solo se c'è il parametro; il token firmato si verifica in Capture.
        if (isset($_GET['nucleo_capture'])) {
            self::call('Nucleo\\Assets\\Capture');
        }

        // Signed preview links for agents: only when the request carries one.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- carica la classe solo se c'è il parametro; il token firmato si verifica in Preview.
        if (isset($_GET['nucleo_preview'])) {
            self::call('Nucleo\\Abilities\\Preview');
        }

        $context = (new ContextDetector())->detect();

        // WP Rocket only: keeps Nucleo CSS out of Remove Unused CSS (its jobs also run on frontend requests).
        if (defined('WP_ROCKET_VERSION')) {
            self::call('Nucleo\\Integrations\\CacheCompat');
        }

        if ($context === Context::Frontend) {
            self::frontend();

            return;
        }

        self::writers();

        if ($context === Context::Cli) {
            self::call('Nucleo\\Cli\\Commands');
            self::call('Nucleo\\Cli\\SiteCommands');
            self::call('Nucleo\\Cli\\DocsCommand');

            return;
        }

        if ($context === Context::Admin) {
            // Requirements are not checked outside admin: they cost I/O.
            $blocking = array_filter(
                Requirements::fromEnvironment()->check(),
                static fn ($r): bool => $r->blocking && !$r->ok,
            );
            if ($blocking !== []) {
                add_action('admin_notices', static function () use ($blocking): void {
                    $items = '';
                    foreach ($blocking as $r) {
                        $items .= '<li>' . esc_html($r->message) . '</li>';
                    }
                    echo wp_kses_post('<div class="notice notice-error"><p>'
                        . esc_html__('Nucleo cannot start:', 'nucleo')
                        . '</p><ul>' . $items . '</ul></div>');
                });
            }
            self::call('Nucleo\\OAuth\\Authorize');
            self::call('Nucleo\\Admin\\Editor');
            self::call('Nucleo\\Admin\\AgentsPage');
            self::call('Nucleo\\Admin\\Menu');
            self::call('Nucleo\\Admin\\AdminApp');
            self::call('Nucleo\\Admin\\Links');
            // Notice when a minify or combine plugin rewrites Nucleo CSS.
            self::call('Nucleo\\Support\\Conflicts');
        }
    }

    /** Richieste del sito pubblico: runtime più tipi e tassonomie del modello (URL dei contenuti, archivi, collezioni). */
    public static function frontend(): void
    {
        add_action('init', [ModelRegistrar::class, 'registerAll']);
        Bootstrap::register();
        // Il pacchetto Free non contiene src/Integrations/Woo: senza la cartella niente hook (un callback mancante è un errore fatale). is_dir e non class_exists: il front-end non carica la classe.
        if (is_dir(__DIR__ . '/Integrations/Woo')) {
            // Campi aggiuntivi del checkout: solo se ne sono salvati. La classe si carica quando WooCommerce si avvia.
            if (get_option('nucleo_woo_checkout_fields')) {
                add_action('woocommerce_init', ['Nucleo\\Integrations\\Woo\\CheckoutFields', 'register']);
            }
            // phpcs:disable WordPress.Security.NonceVerification -- solo presenza dei parametri: decide quali hook agganciare.
            // POST classico di «Aggiungi al carrello» (senza JavaScript o prima dello script): variante dagli attributi.
            if (isset($_REQUEST['add-to-cart'])) {
                add_action('wp_loaded', ['Nucleo\\Integrations\\Woo\\VariationForm', 'resolveRequest'], 19);
            }
            // AJAX del negozio classico di WooCommerce: il contatore del mini-cart arriva tra i frammenti della risposta.
            if (isset($_GET['wc-ajax'])) {
                add_filter('woocommerce_add_to_cart_fragments', ['Nucleo\\Integrations\\Woo\\MiniCartRenderer', 'fragments']);
            }
            // phpcs:enable WordPress.Security.NonceVerification
        }
        // Solo le richieste della sitemap: le altre pagine non caricano il modulo né il filtro.
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '';
        if (str_contains($uri, 'sitemap')) {
            StructuredData::registerSitemap();
        }
    }

    /**
     * Modules that react to content changes. Not on plain frontend requests:
     * there they would cost callbacks and nothing writes content.
     */
    private static function writers(): void
    {
        // Admin, REST e CLI: tabelle aggiunte da una versione nuova. Una get_option autoload, mai sul frontend.
        Activation::maybeUpgrade();
        // Store di un'altra generazione o vuoto (reset o import del database): si sistema in admin, mai sul frontend.
        add_action('admin_init', [\Nucleo\Storage\StoreMaintenance::class, 'maintain']);
        self::call('Nucleo\\Layout\\PostTypes');
        // Formati AVIF e WebP accanto alle immagini caricate, se il sito li ha accesi (opzione imageFormats).
        self::call('Nucleo\\Media\\ImageFormats');
        self::call('Nucleo\\Layout\\LayoutPublisher');
        self::call('Nucleo\\Compiler\\Invalidator');
        self::call('Nucleo\\Assets\\AssetAudit');
        self::call('Nucleo\\Assets\\RulesPublisher');
        self::call('Nucleo\\Bindings\\MetaWatcher');
        self::call('Nucleo\\Runtime\\Collection\\ContentVersion');
        // Tipo privato degli invii dei moduli nativi.
        self::call('Nucleo\\Forms\\FormActions');
        // Esito degli invii email e, in local/development, registro delle email al posto dell'invio.
        self::call('Nucleo\\Forms\\DevMail');
        self::call('Nucleo\\Content\\FieldsMetaBox');
        // Impostazioni Nucleo della pagina (layout per area, titolo, larghezza) e passaggio tra editor Nucleo e WordPress.
        self::call('Nucleo\\Content\\PageSettings');
        self::call('Nucleo\\Content\\EditorMode');
        // Controllo di qualità anche sulla pubblicazione dall'editor di WordPress.
        self::call('Nucleo\\Content\\PublishGate');
        self::call('Nucleo\\Kits\\KitPreview');
        self::call('Nucleo\\Integrations\\Multilingual');
        // Pro edition only: licence page and updates (the Free package has no such class).
        self::call('Nucleo\\Pro\\Plugin');
        // Hooks only; it checks for WooCommerce when they fire (WooCommerce loads after Nucleo).
        self::call('Nucleo\\Integrations\\WooCommerce');
    }

    /** Calls Class::register() when the module exists. */
    private static function call(string $class): void
    {
        if (class_exists($class) && method_exists($class, 'register')) {
            $class::register();
        }
    }
}
