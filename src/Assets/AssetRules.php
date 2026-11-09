<?php

declare(strict_types=1);

namespace Nucleo\Assets;

/**
 * Compila le regole in una tabella: template (o chiave d'oggetto) => handle => voce.
 */
final class AssetRules
{
    private const ACTIONS = ['dequeue', 'defer', 'replace'];
    private const STRATEGIES = ['defer', 'async', 'interaction', 'idle'];
    private const KEY_PATTERN = '/^(\*|[a-z0-9][a-z0-9-]*)(:\d+)?$/';

    /**
     * Le regole non valide vengono ignorate. Con più regole sulla stessa chiave vince l'ultima.
     *
     * @param list<array<string, mixed>> $rules
     * @return array<string, array<string, array{action: string, except: list<string>, strategy?: string, with?: string}>>
     */
    public static function compile(array $rules): array
    {
        $table = [];
        foreach ($rules as $rule) {
            $compiled = self::compileRule($rule);
            if ($compiled === null) {
                continue;
            }
            [$handle, $templates, $entry] = $compiled;
            foreach ($templates as $template) {
                $table[$template][$handle] = $entry;
            }
        }

        return $table;
    }

    /**
     * @return array{0: string, 1: list<string>, 2: array{action: string, except: list<string>, strategy?: string, with?: string}}|null
     */
    private static function compileRule(mixed $rule): ?array
    {
        if (!is_array($rule)) {
            return null;
        }
        $action = $rule['action'] ?? null;
        $handle = $rule['handle'] ?? null;
        if (!in_array($action, self::ACTIONS, true) || !is_string($handle) || $handle === '') {
            return null;
        }

        $templates = $rule['templates'] ?? ['*'];
        $except = $rule['except'] ?? [];
        if (!self::validKeys($templates, true) || !self::validKeys($except, false)) {
            return null;
        }

        $entry = ['action' => $action, 'except' => $except];
        if ($action === 'defer') {
            $strategy = $rule['strategy'] ?? 'defer';
            if (!in_array($strategy, self::STRATEGIES, true)) {
                return null;
            }
            $entry['strategy'] = $strategy;
        } elseif ($action === 'replace') {
            $with = $rule['with'] ?? null;
            if (!is_string($with) || $with === '') {
                return null;
            }
            $entry['with'] = $with;
        }

        return [$handle, $templates, $entry];
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
}
