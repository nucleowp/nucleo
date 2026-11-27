<?php

declare(strict_types=1);

namespace Nucleo\Assets;

defined('ABSPATH') || exit;

use Nucleo\Compiler\Invalidator;
use Nucleo\Integrations\Forms\FormAssets;
use Nucleo\Services;
use Nucleo\Support\Optional;

/**
 * Salva le regole asset dell'utente e pubblica l'artefatto 'asset-rules' con la tabella compilata,
 * le regole sorgente e le varianti CSS ridotte.
 */
final class RulesPublisher
{
    private const OPTION = 'nucleo_asset_rules';
    private const WARNINGS = 'nucleo_asset_rules_warnings';
    private const ARTIFACT = 'asset-rules';
    /** Chiavi vecchie salvate nei dati: si leggono come le chiavi di TemplateKey. */
    private const LEGACY_KEYS = ['post' => 'single', 'category' => 'archive', 'product_cat' => 'product-archive'];

    /**
     * Sostituisce le regole utente e ricompila la tabella. Le varianti ridotte già prodotte si conservano.
     * Gli slug page:<slug> e post:<slug> diventano ID in questo momento; le regole con slug sconosciuto
     * vengono saltate. Restituisce gli avvisi (una riga per regola saltata).
     *
     * @param list<array<string, mixed>> $rules
     * @return list<string>
     */
    public static function publish(array $rules): array
    {
        $rules = array_values($rules);
        update_option(self::OPTION, $rules, false);

        // Gli avvisi si calcolano sulle regole risolte: uno slug valido non è un campo non valido.
        [$kept, $warnings] = self::resolve($rules);
        $warnings = [...$warnings, ...self::review($kept)];
        update_option(self::WARNINGS, $warnings, false);
        $resolved = array_values($kept);

        $artifact = Services::store()->get(self::ARTIFACT) ?? [];
        Services::store()->put(self::ARTIFACT, [
            'table' => self::compileRules($resolved),
            'sources' => AssetRules::compileSources($resolved),
            'purged' => self::purgedMap($artifact),
            'delayed' => self::delayedMap($artifact),
        ]);

        return $warnings;
    }

    /**
     * Avvisi sulle regole: quelle non valide (ignorate), i ritardi al gesto su script dell'elenco «mai ritardare» e,
     * con i dati dell'audit (edizione Pro), i ritardi che su un template restano un normale defer.
     * Per le regole sorgente valide: consenso, pagamento e captcha senza force, e i tipi di misura e pubblicità.
     *
     * @param array<int, array<string, mixed>> $rules regole risolte, con la posizione originale come chiave
     * @return list<string>
     */
    private static function review(array $rules): array
    {
        $warnings = [];
        foreach (AssetRules::issues($rules) as $issue) {
            $warnings[] = sprintf(/* translators: 1: position of the rule in the list, 2: asset handle, 3: name of the invalid field */ __('Asset rule %1$d (\'%2$s\') ignored: \'%3$s\' is not valid.', 'nucleo'), $issue['index'] + 1, $issue['handle'], $issue['code']);
        }
        foreach ($rules as $rule) {
            $handle = $rule['handle'] ?? null;
            $lazy = ($rule['action'] ?? '') === 'defer' && in_array($rule['strategy'] ?? '', ['interaction', 'idle'], true);
            if ($lazy && is_string($handle) && ($rule['force'] ?? false) !== true && ScriptPolicy::isProtected($handle)) {
                $warnings[] = sprintf(/* translators: %s: asset handle */ __('Asset rule \'%s\': this script is on the never-delay list, so it stays a normal defer. Add "force": true to delay it anyway.', 'nucleo'), $handle);
            }
        }
        foreach ($rules as $rule) {
            if (AssetRules::compileSources([$rule]) === []) {
                continue;
            }
            $text = self::ruleName($rule);
            $kind = SourceRules::kind($text);
            if (in_array($kind, SourceRules::NEVER_KINDS, true) && ($rule['force'] ?? false) !== true) {
                $warnings[] = sprintf(/* translators: %s: part of the script URL or of the inline code of the source rule */ __('Asset rule \'%s\': consent, payment and captcha scripts are not delayed. Add "force": true to delay it anyway.', 'nucleo'), $text);
            }
            if (in_array($kind, SourceRules::TRACKING_KINDS, true)) {
                $warnings[] = sprintf(/* translators: %s: part of the script URL or of the inline code of the source rule */ __('Asset rule \'%s\': this delays a measurement or advertising script. Visits that end without a tap, a key press or a scroll are not counted.', 'nucleo'), $text);
            }
        }
        // Solo edizione Pro: il registro dell'audit conosce dipendenti e codice inline di ogni script.
        $audit = Optional::call('Nucleo\\Assets\\AssetRegistry', 'ruleWarnings', array_values($rules));

        return [...$warnings, ...(is_array($audit) ? array_values(array_filter($audit, 'is_string')) : [])];
    }

    /** @return list<string> avvisi dell'ultima pubblicazione */
    public static function warnings(): array
    {
        $stored = get_option(self::WARNINGS, []);

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    /** Activation step and plugin (de)activation: default rules follow the active plugins. */
    public static function install(): void
    {
        self::publish(self::rules());
    }

    public static function register(): void
    {
        foreach (['activated_plugin', 'deactivated_plugin', 'nucleo/document_saved'] as $hook) {
            if (has_action($hook, [self::class, 'install']) === false) {
                add_action($hook, [self::class, 'install'], 20, 0);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public static function rules(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return [];
        }

        return array_values(array_filter($stored, 'is_array'));
    }

    /** Registra la variante ridotta di un handle per un template. */
    public static function setPurged(string $template, string $handle, string $url): void
    {
        $artifact = Services::store()->get(self::ARTIFACT) ?? [];
        $table = is_array($artifact['table'] ?? null) ? $artifact['table'] : self::compileRules(self::rules());

        $purged = self::purgedMap($artifact);
        $byTemplate = is_array($purged[$template] ?? null) ? $purged[$template] : [];
        $byTemplate[$handle] = $url;
        $purged[$template] = $byTemplate;

        Services::store()->put(self::ARTIFACT, ['table' => $table, 'sources' => self::sourcesMap($artifact), 'purged' => $purged, 'delayed' => self::delayedMap($artifact)]);
    }

    /**
     * Script che l'ottimizzatore JS ritarda al gesto su un template, con il selettore del clic da ripetere.
     * Il runtime li applica come regole defer interaction con group e force, dove nessuna regola utente tocca l'handle.
     *
     * @param list<string> $handles
     */
    public static function setDelayed(string $template, array $handles, ?string $replay = null): void
    {
        $artifact = Services::store()->get(self::ARTIFACT) ?? [];
        $delayed = self::delayedMap($artifact);
        $delayed[$template] = ['handles' => array_values($handles)] + ($replay !== null ? ['replay' => $replay] : []);
        self::putDelayed($artifact, $delayed);
    }

    /** Toglie i ritardi dell'ottimizzatore JS di un template; tabella e varianti restano invariate. */
    public static function clearDelayed(string $template): void
    {
        $artifact = Services::store()->get(self::ARTIFACT) ?? [];
        $delayed = self::delayedMap($artifact);
        if (!isset($delayed[$template])) {
            return;
        }
        unset($delayed[$template]);
        self::putDelayed($artifact, $delayed);
    }

    /**
     * @param array<string, mixed> $artifact
     * @param array<string, array<string, mixed>> $delayed
     */
    private static function putDelayed(array $artifact, array $delayed): void
    {
        $table = is_array($artifact['table'] ?? null) ? $artifact['table'] : self::compileRules(self::rules());
        Services::store()->put(self::ARTIFACT, ['table' => $table, 'sources' => self::sourcesMap($artifact), 'purged' => self::purgedMap($artifact), 'delayed' => $delayed]);
    }

    /**
     * @param array<string, mixed> $artifact
     * @return array<string, array<string, mixed>> template => ['handles' => list<string>, 'replay'? => string]
     */
    private static function delayedMap(array $artifact): array
    {
        $delayed = $artifact['delayed'] ?? [];

        return is_array($delayed) ? array_filter($delayed, 'is_array') : [];
    }

    /**
     * @param array<string, mixed> $artifact
     * @return array<string, mixed> scope => list<voce delle regole sorgente>
     */
    private static function sourcesMap(array $artifact): array
    {
        $sources = $artifact['sources'] ?? [];

        return is_array($sources) ? $sources : [];
    }

    /** Toglie le varianti ridotte di un template; la tabella resta invariata. */
    public static function clearPurged(string $template): void
    {
        $artifact = Services::store()->get(self::ARTIFACT) ?? [];
        $purged = self::purgedMap($artifact);
        if (!isset($purged[$template])) {
            return;
        }
        unset($purged[$template]);

        $table = is_array($artifact['table'] ?? null) ? $artifact['table'] : self::compileRules(self::rules());
        Services::store()->put(self::ARTIFACT, ['table' => $table, 'sources' => self::sourcesMap($artifact), 'purged' => $purged, 'delayed' => self::delayedMap($artifact)]);
    }

    /**
     * Le regole predefinite di WooCommerce precedono quelle dell'utente: con più regole sulla
     * stessa chiave vince l'ultima, quindi una regola utente sostituisce quella predefinita.
     *
     * @param list<array<string, mixed>> $rules
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function compileRules(array $rules): array
    {
        // Form plugin assets only on the pages that hold a form (deps form:<plugin>), and only for the form plugins in use.
        $forms = FormAssets::rules(static fn (string $dep): array => array_map(
            static fn (int $id): string => get_post_type($id) . ':' . $id,
            Invalidator::dependents($dep),
        ), FormAssets::active());
        // Pro edition only: WooCommerce defaults.
        $woo = class_exists('WooCommerce') ? Optional::call('Nucleo\\Integrations\\WooCommerce', 'defaultRules') : [];
        $defaults = [...(is_array($woo) ? array_values($woo) : []), ...$forms];
        $all = [...$defaults, ...$rules];

        return AssetRules::compile($all);
    }

    /**
     * Risolve le regole: page:<slug> e post:<slug> in page:<ID> e post:<ID> (templates ed except).
     * Una regola con uno slug che non esiste viene saltata e segnalata. Le regole tenute conservano come chiave
     * la posizione nell'elenco ricevuto.
     *
     * @param list<array<string, mixed>> $rules
     * @return array{0: array<int, array<string, mixed>>, 1: list<string>}
     */
    private static function resolve(array $rules): array
    {
        $resolved = [];
        $warnings = [];
        foreach ($rules as $index => $rule) {
            $missing = null;
            foreach (['templates', 'except'] as $field) {
                if (!is_array($rule[$field] ?? null)) {
                    continue;
                }
                $keys = [];
                foreach ($rule[$field] as $key) {
                    $id = is_string($key) ? self::resolveKey($key) : $key;
                    if ($id === null) {
                        $missing = (string) $key;
                        break 2;
                    }
                    $keys[] = $id;
                }
                $rule[$field] = $keys;
            }
            if ($missing !== null) {
                $warnings[] = sprintf(/* translators: 1: asset handle or part of the script URL or inline code, 2: page or post slug */ __('Asset rule \'%1$s\' skipped: no page or post with slug \'%2$s\'.', 'nucleo'), self::ruleName($rule), $missing);
                continue;
            }
            $resolved[$index] = $rule;
        }

        return [$resolved, $warnings];
    }

    /**
     * Testo che identifica una regola nei messaggi: l'handle; per una regola sorgente, la parte di src o di code.
     *
     * @param array<string, mixed> $rule
     */
    private static function ruleName(array $rule): string
    {
        foreach (['handle', 'src', 'code'] as $field) {
            if (is_string($rule[$field] ?? null)) {
                return $rule[$field];
            }
        }

        return '';
    }

    /**
     * page:<slug> o post:<slug> in page:<ID> o post:<ID>; null se lo slug non esiste.
     * Le chiavi già numeriche o di altra forma restano come sono.
     */
    private static function resolveKey(string $key): ?string
    {
        if (preg_match('/^(page|post):(.+)$/', $key, $m) !== 1 || ctype_digit($m[2])) {
            return $key;
        }
        $post = get_page_by_path($m[2], OBJECT, $m[1]);

        return $post instanceof \WP_Post ? $m[1] . ':' . $post->ID : null;
    }

    /**
     * Chiave di template come la produce TemplateKey; le chiavi vecchie (post, category, product_cat) si mappano.
     */
    public static function templateKey(string $key): string
    {
        return self::LEGACY_KEYS[$key] ?? $key;
    }

    /**
     * @param array<string, mixed> $artifact
     * @return array<string, mixed>
     */
    private static function purgedMap(array $artifact): array
    {
        $purged = $artifact['purged'] ?? [];
        if (!is_array($purged)) {
            return [];
        }

        // Chiavi vecchie (post, category, product_cat) mappate come quelle di TemplateKey.
        $out = [];
        foreach ($purged as $template => $byHandle) {
            $key = self::templateKey((string) $template);
            $out[$key] = [...($out[$key] ?? []), ...(is_array($byHandle) ? $byHandle : [])];
        }

        return $out;
    }
}
