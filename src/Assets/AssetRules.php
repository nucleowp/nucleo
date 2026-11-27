<?php

declare(strict_types=1);

namespace Nucleo\Assets;

defined('ABSPATH') || exit;

/**
 * Compila le regole in una tabella: template (o chiave d'oggetto) => handle => voce.
 */
final class AssetRules
{
    private const ACTIONS = ['dequeue', 'defer', 'replace', 'keep'];
    private const STRATEGIES = ['defer', 'async', 'interaction', 'idle'];
    private const SOURCE_STRATEGIES = ['interaction', 'idle'];
    private const KEY_PATTERN = '/^(\*|[a-z0-9][a-z0-9-]*)(:\d+)?$/';
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_\/-]+$/';
    private const REPLAY_PATTERN = '/\A[^<>{}]{1,200}\z/u';
    private const SOURCE_PATTERN = '/\A[^<>]{3,200}\z/u';
    private const CODE_PATTERN = '/\A.{3,200}\z/su';

    /**
     * Le regole non valide vengono ignorate. Con più regole sulla stessa chiave vince l'ultima.
     * Le regole sorgente (src o code) non entrano qui: vedi compileSources().
     *
     * Voce defer: force e group (true) passano come sono; replay è un selettore CSS (1-200 caratteri, senza < > { }).
     *
     * @param list<array<string, mixed>> $rules
     * @return array<string, array<string, array{action: string, except: list<string>, strategy?: string, with?: string, force?: true, group?: true, replay?: string, uses?: list<string>}>>
     */
    public static function compile(array $rules): array
    {
        $table = [];
        foreach ($rules as $rule) {
            if (self::isSource($rule)) {
                continue;
            }
            $checked = self::check($rule);
            if ($checked['entry'] === null) {
                continue;
            }
            foreach ($checked['templates'] as $template) {
                $table[$template][$checked['handle']] = $checked['entry'];
            }
        }

        return $table;
    }

    /**
     * Regole sorgente (src o code al posto di handle), per gli script fuori dalla coda: scope => voci, nell'ordine delle regole.
     * compile() le salta senza segnalarle; issues() segnala quelle non valide con il codice del campo e, come handle, il testo di src o code.
     *
     * @param list<array<string, mixed>> $rules
     * @return array<string, list<array{src?: string, code?: string, strategy: string, except: list<string>, force?: true, replay?: string}>>
     */
    public static function compileSources(array $rules): array
    {
        $sources = [];
        foreach ($rules as $rule) {
            if (!self::isSource($rule)) {
                continue;
            }
            $checked = self::check($rule);
            if ($checked['source'] === null) {
                continue;
            }
            foreach ($checked['templates'] as $template) {
                $sources[$template][] = $checked['source'];
            }
        }

        return $sources;
    }

    /**
     * Le regole che compile() ignora, con il primo controllo che fallisce. index è la chiave della regola nell'array.
     *
     * @param array<int, array<string, mixed>> $rules
     * @return list<array{index: int, handle: string, code: string}>
     */
    public static function issues(array $rules): array
    {
        $issues = [];
        foreach ($rules as $index => $rule) {
            $checked = self::check($rule);
            if ($checked['code'] !== null) {
                $issues[] = ['index' => $index, 'handle' => $checked['handle'], 'code' => $checked['code']];
            }
        }

        return $issues;
    }

    /**
     * Regola sorgente: ha la chiave src o code al posto di handle.
     */
    private static function isSource(mixed $rule): bool
    {
        return is_array($rule) && (array_key_exists('src', $rule) || array_key_exists('code', $rule));
    }

    /**
     * Unico punto di validazione, usato da compile(), compileSources() e issues().
     * 'entry' è la voce per handle (null se la regola non è valida o è sorgente); 'source' è la voce sorgente (null se non lo è).
     *
     * @return array{code: string|null, handle: string, templates: list<string>, entry: array{action: string, except: list<string>, strategy?: string, with?: string, force?: true, group?: true, replay?: string, uses?: list<string>}|null, source: null}|array{code: string|null, handle: string, templates: list<string>, entry: null, source: array{src?: string, code?: string, strategy: string, except: list<string>, force?: true, replay?: string}|null}
     */
    private static function check(mixed $rule): array
    {
        if (!is_array($rule)) {
            return self::rejected('not-a-rule');
        }
        if (self::isSource($rule)) {
            return self::checkSource($rule);
        }
        $handle = is_string($rule['handle'] ?? null) ? $rule['handle'] : '';
        $action = $rule['action'] ?? null;
        if (!in_array($action, self::ACTIONS, true)) {
            return self::rejected('action', $handle);
        }
        if ($handle === '') {
            return self::rejected('handle');
        }

        $templates = $rule['templates'] ?? [];
        if ($templates === []) {
            $templates = ['*'];
        }
        if (!self::validKeys($templates, true)) {
            return self::rejected('templates', $handle);
        }
        $except = $rule['except'] ?? [];
        if (!self::validKeys($except, false)) {
            return self::rejected('except', $handle);
        }

        $entry = ['action' => $action, 'except' => $except];
        if ($action === 'defer') {
            $strategy = $rule['strategy'] ?? 'defer';
            if (!in_array($strategy, self::STRATEGIES, true)) {
                return self::rejected('strategy', $handle);
            }
            $entry['strategy'] = $strategy;
            if (($rule['force'] ?? null) === true) {
                $entry['force'] = true;
            }
            if (($rule['group'] ?? null) === true) {
                $entry['group'] = true;
            }
            $replay = $rule['replay'] ?? null;
            if ($replay !== null) {
                if (!self::validReplay($replay)) {
                    return self::rejected('replay', $handle);
                }
                $entry['replay'] = $replay;
            }
        } elseif ($action === 'replace') {
            $with = $rule['with'] ?? null;
            if (!is_string($with) || $with === '') {
                return self::rejected('with', $handle);
            }
            $entry['with'] = $with;
        }

        $uses = $rule['uses'] ?? null;
        if ($uses !== null) {
            if (!self::validUses($uses)) {
                return self::rejected('uses', $handle);
            }
            $entry['uses'] = $uses;
        }

        return ['code' => null, 'handle' => $handle, 'templates' => $templates, 'entry' => $entry, 'source' => null];
    }

    /**
     * Controllo di una regola sorgente. Il testo di src o code fa da handle nel risultato; la voce va in 'source'.
     *
     * @param array<string, mixed> $rule
     * @return array{code: string|null, handle: string, templates: list<string>, entry: null, source: array{src?: string, code?: string, strategy: string, except: list<string>, force?: true, replay?: string}|null}
     */
    private static function checkSource(array $rule): array
    {
        $field = array_key_exists('src', $rule) ? 'src' : 'code';
        $text = is_string($rule[$field]) ? $rule[$field] : '';
        if (array_key_exists('handle', $rule) || (array_key_exists('src', $rule) && array_key_exists('code', $rule))) {
            return self::rejected('target', $text);
        }
        if (($rule['action'] ?? null) !== 'defer') {
            return self::rejected('action', $text);
        }
        $value = $rule[$field];
        if ($field === 'code' && is_string($value)) {
            $value = SourceRules::squash($value);
        }
        $pattern = $field === 'src' ? self::SOURCE_PATTERN : self::CODE_PATTERN;
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            return self::rejected($field, $text);
        }

        $strategy = $rule['strategy'] ?? 'interaction';
        if (!in_array($strategy, self::SOURCE_STRATEGIES, true)) {
            return self::rejected('strategy', $text);
        }
        $templates = $rule['templates'] ?? [];
        if ($templates === []) {
            $templates = ['*'];
        }
        if (!self::validKeys($templates, true)) {
            return self::rejected('templates', $text);
        }
        $except = $rule['except'] ?? [];
        if (!self::validKeys($except, false)) {
            return self::rejected('except', $text);
        }

        $source = [$field => $value, 'strategy' => $strategy, 'except' => $except];
        if (($rule['force'] ?? null) === true) {
            $source['force'] = true;
        }
        $replay = $rule['replay'] ?? null;
        if ($replay !== null) {
            if (!self::validReplay($replay)) {
                return self::rejected('replay', $text);
            }
            $source['replay'] = $replay;
        }

        return ['code' => null, 'handle' => $text, 'templates' => $templates, 'entry' => null, 'source' => $source];
    }

    /**
     * @return array{code: string, handle: string, templates: list<string>, entry: null, source: null}
     */
    private static function rejected(string $code, string $handle = ''): array
    {
        return ['code' => $code, 'handle' => $handle, 'templates' => [], 'entry' => null, 'source' => null];
    }

    /**
     * @phpstan-assert-if-true list<string> $keys
     */
    private static function validKeys(mixed $keys, bool $required): bool
    {
        if (!is_array($keys) || !array_is_list($keys) || ($required && $keys === [])) {
            return false;
        }
        foreach ($keys as $key) {
            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @phpstan-assert-if-true list<string> $uses
     */
    private static function validUses(mixed $uses): bool
    {
        if (!is_array($uses) || !array_is_list($uses) || $uses === []) {
            return false;
        }
        foreach ($uses as $token) {
            if (!is_string($token) || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @phpstan-assert-if-true string $selector
     */
    /** @internal Selettore CSS di replay valido: usato anche dall'ottimizzatore JS. */
    public static function validReplay(mixed $selector): bool
    {
        return is_string($selector) && preg_match(self::REPLAY_PATTERN, $selector) === 1;
    }
}
