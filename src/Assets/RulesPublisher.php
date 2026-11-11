<?php

declare(strict_types=1);

namespace Nucleo\Assets;

defined('ABSPATH') || exit;

use Nucleo\Compiler\Invalidator;
use Nucleo\Integrations\Forms\FormAssets;
use Nucleo\Services;
use Nucleo\Support\Optional;

/**
 * Salva le regole asset dell'utente e pubblica l'artefatto 'asset-rules' con la tabella compilata
 * e le varianti CSS ridotte.
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

        [$resolved, $warnings] = self::resolve($rules);
        update_option(self::WARNINGS, $warnings, false);

        $artifact = Services::store()->get(self::ARTIFACT) ?? [];
        Services::store()->put(self::ARTIFACT, [
            'table' => self::compileRules($resolved),
            'purged' => self::purgedMap($artifact),
        ]);

        return $warnings;
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

        Services::store()->put(self::ARTIFACT, ['table' => $table, 'purged' => $purged]);
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
        Services::store()->put(self::ARTIFACT, ['table' => $table, 'purged' => $purged]);
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
        // Form plugin assets only on the pages that hold a form (deps form:<plugin>).
        $forms = FormAssets::rules(static fn (string $dep): array => array_map(
            static fn (int $id): string => get_post_type($id) . ':' . $id,
            Invalidator::dependents($dep),
        ));
        // Pro edition only: WooCommerce defaults.
        $woo = class_exists('WooCommerce') ? Optional::call('Nucleo\\Integrations\\WooCommerce', 'defaultRules') : [];
        $defaults = [...(is_array($woo) ? array_values($woo) : []), ...$forms];
        $all = [...$defaults, ...$rules];

        return AssetRules::compile($all);
    }

    /**
     * Risolve le regole: page:<slug> e post:<slug> in page:<ID> e post:<ID> (templates ed except).
     * Una regola con uno slug che non esiste viene saltata e segnalata.
     *
     * @param list<array<string, mixed>> $rules
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private static function resolve(array $rules): array
    {
        $resolved = [];
        $warnings = [];
        foreach ($rules as $rule) {
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
                $handle = is_string($rule['handle'] ?? null) ? $rule['handle'] : '';
                $warnings[] = sprintf(/* translators: 1: asset handle, 2: page or post slug */ __('Asset rule \'%1$s\' skipped: no page or post with slug \'%2$s\'.', 'nucleo'), $handle, $missing);
                continue;
            }
            $resolved[] = $rule;
        }

        return [$resolved, $warnings];
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
