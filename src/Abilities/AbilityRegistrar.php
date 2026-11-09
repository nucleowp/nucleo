<?php

declare(strict_types=1);

namespace Nucleo\Abilities;

use Nucleo\Abilities\Read\CatalogGet;
use Nucleo\Abilities\Read\CatalogList;
use Nucleo\Abilities\Read\DocGet;
use Nucleo\Abilities\Read\DocList;
use Nucleo\Abilities\Read\DocPreview;
use Nucleo\Abilities\Read\DocRevisions;
use Nucleo\Abilities\Read\DocTexts;
use Nucleo\Abilities\Read\DocValidate;
use Nucleo\Abilities\Read\QualityCheck;
use Nucleo\Abilities\Read\SiteDescribe;
use Nucleo\Abilities\Read\Status;
use Nucleo\Abilities\Read\TokensGet;
use Nucleo\OAuth\CurrentToken;
use Nucleo\OAuth\Scopes;

/** Registra le ability nucleo/* con l'Abilities API di WordPress. */
final class AbilityRegistrar
{
    private const CATEGORY = 'nucleo';

    /**
     * Nomi delle classi di scrittura in src/Abilities/Write/: quelle assenti vengono saltate.
     * @return list<string>
     */
    private static function writeClasses(): array
    {
        $classes = ['Write\\DocCreate', 'Write\\DocPatch', 'Write\\DocPublish', 'Write\\DocRollback', 'Write\\TokensSet',
            'Write\\CacheRebuild', 'Write\\MediaImport', 'Write\\DocTextsSet', 'Write\\DocTranslate', 'Write\\FontsImport',
            'Write\\AssetsAudit', 'Write\\AssetsRulesSet', 'Write\\CssPurge',
            'Read\\AssetsReport', 'Read\\QualityScan', 'Read\\SiteExport', 'Write\\SiteImport',
            'Read\\KitList', 'Write\\KitApply', 'Write\\CriticalSet', 'Write\\LayoutConditionsSet',
            'Read\\SettingsGet', 'Write\\SettingsSet', 'Read\\ModelGet', 'Read\\ModelValidate', 'Write\\ModelSet', 'Read\\FieldsGet', 'Write\\FieldsSet',
            'Read\\CssStatus', 'Write\\CssOptimize', 'Write\\CssOptimizeApply', 'Write\\CssOptimizeOff',
            'Write\\MediaUpload', 'Read\\MenuList', 'Write\\MenuSet', 'Write\\SiteOptionsSet', 'Write\\IconsSet', 'Write\\GapReport', 'Write\\MediaFormats', 'Read\\GapList', 'Read\\ComponentsList',
            'Read\\ContentList', 'Read\\ContentGet', 'Write\\ContentUpsert', 'Read\\TermsGet', 'Write\\TermsSet',
            'Read\\Guide', 'Write\\WooCheckoutFieldsSet', 'Write\\WooCheckoutPartsSet'];
        // Il pacchetto per WordPress.org non contiene queste ability: niente attivazione di plugin da remoto
        // e niente servizio esterno di critical CSS.

        return $classes;
    }


    /** Abilities di WooCommerce: si registrano solo con WooCommerce attivo. */
    private const WOO = ['Write\\WooCheckoutFieldsSet', 'Write\\WooCheckoutPartsSet'];

    /** Con input vuoto queste ability leggono soltanto: non contano nel limite delle scritture. */
    private const READ_ON_EMPTY = ['nucleo/woo-checkout-fields-set', 'nucleo/woo-checkout-parts-set'];

    private static bool $registered = false;

    public static function register(): void
    {
        if (!function_exists('wp_register_ability') || self::$registered) {
            return;
        }
        self::$registered = true;

        self::whenHookRuns('wp_abilities_api_categories_init', static function (): void {
            if (!wp_has_ability_category(self::CATEGORY)) {
                wp_register_ability_category(self::CATEGORY, [
                    'label' => 'Nucleo',
                    'description' => 'Nucleo site operations: documents, design system, quality.',
                ]);
            }
        });

        self::whenHookRuns('wp_abilities_api_init', static function (): void {
            foreach (self::definitions() as $definition) {
                $name = $definition->name();
                if (!$name || strtolower($name) !== $name) {
                    continue;
                }
                if (!wp_has_ability($name)) {
                    wp_register_ability($name, self::argsFor($definition));
                }
            }
        });
    }

    /** @return list<AbilityDefinition> */
    public static function definitions(): array
    {
        $definitions = [
            new SiteDescribe(),
            new CatalogList(),
            new CatalogGet(),
            new DocList(),
            new DocGet(),
            new DocValidate(),
            new QualityCheck(),
            new DocRevisions(),
            new TokensGet(),
            new Status(),
            new DocPreview(),
            new DocTexts(),
            new \Nucleo\Abilities\Read\LayoutList(),
            new \Nucleo\Abilities\Read\LayoutExplain(),
            new \Nucleo\Abilities\Read\AuditList(),
            new \Nucleo\Abilities\Read\MailLog(),
            new \Nucleo\Abilities\Read\BindFields(),
            new \Nucleo\Abilities\Read\SectionList(),
            new \Nucleo\Abilities\Read\DocsSearch(),
        ];

        foreach (self::writeClasses() as $short) {
            // Senza WooCommerce le ability woo-* risponderebbero solo woo_missing: non entrano in tools/list.
            if (in_array($short, self::WOO, true) && !class_exists('WooCommerce')) {
                continue;
            }
            $class = __NAMESPACE__ . '\\' . $short;
            if (class_exists($class) && is_a($class, AbilityDefinition::class, true)) {
                $definitions[] = new $class();
            }
        }

        return $definitions;
    }

    /**
     * Argomenti completi per wp_register_ability(): categoria, callback e controllo degli scope OAuth.
     * @return array<string,mixed>
     */
    public static function argsFor(AbilityDefinition $definition): array
    {
        $name = $definition->name();
        $args = $definition->args();
        $args['category'] = self::CATEGORY;
        // Le letture REST vanno in GET e un oggetto vuoto non si può scrivere nella query: senza campi obbligatori vale {}.
        if (is_array($args['input_schema'] ?? null) && ($args['input_schema']['required'] ?? []) === [] && !array_key_exists('default', $args['input_schema'])) {
            $args['input_schema']['default'] = [];
        }
        $readonly = ($args['meta']['annotations']['readonly'] ?? false) === true;
        $args['execute_callback'] = static function (mixed $input = null) use ($definition, $name, $readonly): array|\WP_Error {
            $input = is_array($input) ? $input : [];
            // Limite di frequenza per le sole scritture: utente e gruppo di scope.
            // Una lettura con input vuoto non scrive: non consuma il limite. Le simulazioni (dryRun) sì: validano e compilano.
            $writes = !$readonly && !($input === [] && in_array($name, self::READ_ON_EMPTY, true));
            if ($writes && !RateLimiter::hit(self::rateKey($name), (int) apply_filters('nucleo/rate_limit', 60), RateLimiter::WINDOW)) {
                return ['ok' => false, 'error' => [
                    'code' => 'rate_limited',
                    'message' => __('Too many writes in a short time: try again in a few seconds.', 'nucleo'),
                    'retryAfter' => RateLimiter::WINDOW,
                ]];
            }

            return $definition->execute($input);
        };
        $args['permission_callback'] = static function (mixed $input) use ($definition, $name): bool|\WP_Error {
            $input = is_array($input) ? $input : [];
            if (!$definition->permitted($input)) {
                return false;
            }

            $scopes = CurrentToken::scopes();
            $short = explode('/', $name, 2)[1] ?? $name;
            $required = Scopes::forAbility($short);
            $missing = self::missingScope($scopes, $required, $input);
            if ($missing === null && isset($input['postId']) && is_numeric($input['postId'])) {
                $status = get_post_status((int) $input['postId']);
                $missing = self::liveEditNeedsPublish($scopes, $short, is_string($status) ? $status : null) ? Scopes::PUBLISH : null;
            }
            if ($missing === null) {
                return true;
            }

            return new \WP_Error(
                'insufficient_scope',
                /* translators: %s: name of the OAuth scope required by the ability. */
                sprintf(__('Required scope: %s.', 'nucleo'), $missing === '' ? __('not mapped for this ability', 'nucleo') : $missing),
                ['status' => 403, 'scope' => $missing === '' ? null : $missing],
            );
        };
        $args['meta'] = array_merge($args['meta'] ?? [], ['show_in_rest' => true]);

        return $args;
    }

    /**
     * Scope che manca per eseguire l'ability, o null se il permesso basta.
     * Senza token OAuth vale solo la capability. Con token serve lo scope dell'ability e, se l'input pubblica
     * (publish true o status publish), anche nucleo:publish. Stringa vuota se l'ability non ha uno scope mappato.
     *
     * @param list<string>|null $scopes scope del token OAuth, null senza token
     * @param array<string,mixed> $input
     */
    public static function missingScope(?array $scopes, ?string $required, array $input): ?string
    {
        if ($scopes === null) {
            return null;
        }
        if ($required === null) {
            return '';
        }
        if (!in_array($required, $scopes, true)) {
            return $required;
        }
        if (self::publishes($input) && !in_array(Scopes::PUBLISH, $scopes, true)) {
            return Scopes::PUBLISH;
        }

        return null;
    }

    /**
     * L'input pubblica se publish è true, o se uno stato è publish, future o private: quello di primo livello
     * o quello di un elemento di items (content-upsert).
     * @param array<string,mixed> $input
     */
    public static function publishes(array $input): bool
    {
        if (($input['publish'] ?? false) === true) {
            return true;
        }
        // items[].status di content-upsert lo controlla ContentWriter voce per voce: una bozza nello stesso invio passa.
        return in_array($input['status'] ?? null, ['publish', 'future', 'private'], true);
    }

    /**
     * Ability che riscrivono un post esistente indicato da postId: su un post online la modifica va subito online.
     * doc-create con postId aggancia un documento al contenuto esistente e ne riscrive il testo; layout-conditions-set
     * cambia dove compare un layout già pubblicato.
     */
    private const LIVE_EDITS = ['doc-patch', 'doc-texts-set', 'doc-rollback', 'fields-set', 'doc-create', 'layout-conditions-set'];

    /**
     * Modificare un post pubblicato, programmato o privato equivale a pubblicare: senza lo scope publish
     * il token resta sulle bozze, come per content-upsert. Senza token OAuth (scopes null) non si applica.
     * @param ?list<string> $scopes
     */
    public static function liveEditNeedsPublish(?array $scopes, string $ability, ?string $status): bool
    {
        return $scopes !== null
            && in_array($ability, self::LIVE_EDITS, true)
            && in_array($status, ['publish', 'future', 'private'], true)
            && !in_array(Scopes::PUBLISH, $scopes, true);
    }

    /** Chiave del limite: utente corrente e gruppo di scope dell'ability. */
    private static function rateKey(string $name): string
    {
        $short = explode('/', $name, 2)[1] ?? $name;

        return get_current_user_id() . '|' . (Scopes::forAbility($short) ?? $name);
    }

    private static function whenHookRuns(string $hook, callable $callback): void
    {
        if (did_action($hook) > 0) {
            $callback();

            return;
        }
        add_action($hook, $callback);
    }
}
