<?php

declare(strict_types=1);

namespace Nucleo\Site;

defined('ABSPATH') || exit;

use InvalidArgumentException;

/**
 * Sostituisce gli ID del documento con simboli leggibili ("page:chi-siamo", "section:cta") e viceversa.
 * Un valore senza nome resta numerico: l'export non inventa simboli.
 */
final class SymbolicRefs
{
    /** Tipi di condizione che portano un ID, con il tipo di simbolo atteso. */
    private const CONDITION_KINDS = [
        'post' => 'post',
        'parent' => 'post',
        'term' => 'term',
        'term_archive' => 'term',
        'author' => 'author',
        'author_archive' => 'author',
    ];

    /** Campi che portano l'URL di un allegato della libreria media. */
    public const MEDIA_KEYS = ['src', 'url', 'href', 'poster'];

    /**
     * @param array<string,mixed> $doc
     * @param callable(string $kind, int $id): ?string $name
     * @return array<string,mixed>
     */
    public static function export(array $doc, callable $name): array
    {
        return self::walk($doc, static fn (string $kind, mixed $value): mixed => self::exportValue($kind, $value, $name));
    }

    /**
     * @param array<string,mixed> $doc
     * @param callable(string $symbol): ?int $id
     * @return array<string,mixed>
     * @throws InvalidArgumentException se un simbolo non è risolto
     */
    public static function import(array $doc, callable $id): array
    {
        return self::walk($doc, static fn (string $kind, mixed $value): mixed => self::importValue($kind, $value, $id));
    }

    /**
     * Solo i menu di nc/menu: "principale" o "menu:principale" diventano l'ID del menu. Un ID numerico resta com'è.
     * Lo usa il salvataggio dei documenti, così l'ability accetta lo slug come l'import dei file.
     * @param array<string,mixed> $doc
     * @param callable(string $symbol): ?int $id
     * @return array<string,mixed>
     * @throws InvalidArgumentException se il menu non esiste o il riferimento non è a un menu
     */
    public static function menus(array $doc, callable $id): array
    {
        if (is_array($doc['root'] ?? null)) {
            $doc['root'] = self::node($doc['root'], static fn (string $kind, mixed $value): mixed => $kind === 'menu' ? self::importValue($kind, $value, $id) : $value);
        }

        return $doc;
    }

    /**
     * Applica $fn ai valori dei campi MEDIA_KEYS, a ogni profondità del documento.
     * @param array<string,mixed> $doc
     * @param callable(string $value): string $fn
     * @return array<string,mixed>
     */
    public static function mapMedia(array $doc, callable $fn): array
    {
        return self::walkMedia($doc, $fn);
    }

    /**
     * Applica $fn a ogni campo con ID: ref delle sezioni, condizioni di pubblicazione, menu.
     * @param array<string,mixed> $doc
     * @param callable(string $kind, mixed $value): mixed $fn
     * @return array<string,mixed>
     */
    private static function walk(array $doc, callable $fn): array
    {
        $conditions = $doc['meta']['conditions'] ?? null;
        if (is_array($conditions)) {
            foreach (['include', 'exclude'] as $list) {
                $items = $conditions[$list] ?? null;
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $i => $condition) {
                    if (!is_array($condition) || !is_string($condition['type'] ?? null) || !array_key_exists('value', $condition)) {
                        continue;
                    }
                    $kind = self::CONDITION_KINDS[$condition['type']] ?? null;
                    if ($kind !== null) {
                        $items[$i]['value'] = $fn($kind, $condition['value']);
                    }
                }
                $conditions[$list] = $items;
            }
            $doc['meta']['conditions'] = $conditions;
        }

        if (is_array($doc['root'] ?? null)) {
            $doc['root'] = self::node($doc['root'], $fn);
        }

        return $doc;
    }

    /**
     * @param array<string,mixed> $node
     * @param callable(string $kind, mixed $value): mixed $fn
     * @return array<string,mixed>
     */
    private static function node(array $node, callable $fn): array
    {
        if (is_string($node['ref'] ?? null) && str_starts_with($node['ref'], 'section:')) {
            $node['ref'] = $fn('section', $node['ref']);
        }
        if (($node['type'] ?? null) === 'nc/menu' && is_array($node['settings'] ?? null) && array_key_exists('menu', $node['settings'])) {
            $node['settings']['menu'] = $fn('menu', $node['settings']['menu']);
        }
        if (is_array($node['children'] ?? null)) {
            foreach ($node['children'] as $i => $child) {
                if (is_array($child)) {
                    $node['children'][$i] = self::node($child, $fn);
                }
            }
        }

        return $node;
    }

    /**
     * @param array<array-key,mixed> $data
     * @param callable(string $value): string $fn
     * @return array<array-key,mixed>
     */
    private static function walkMedia(array $data, callable $fn): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::walkMedia($value, $fn);
            } elseif (is_string($value) && in_array($key, self::MEDIA_KEYS, true)) {
                $data[$key] = $fn($value);
            }
        }

        return $data;
    }

    /** @param callable(string $kind, int $id): ?string $name */
    private static function exportValue(string $kind, mixed $value, callable $name): mixed
    {
        if ($kind === 'section') {
            if (!is_string($value) || preg_match('/^section:(\d+)$/', $value, $m) !== 1) {
                return $value;
            }

            return $name('section', (int) $m[1]) ?? $value;
        }

        if (!is_int($value)) {
            return $value;
        }

        return $name($kind, $value) ?? $value;
    }

    /** @param callable(string $symbol): ?int $id */
    private static function importValue(string $kind, mixed $value, callable $id): mixed
    {
        if (!is_string($value) || $value === '' || ctype_digit($value) || preg_match('/^section:\d+$/', $value) === 1) {
            return $value;
        }

        // nc/menu accetta "menu:<slug>" e lo slug semplice: "principale" vale "menu:principale".
        if ($kind === 'menu' && !str_contains($value, ':')) {
            $value = 'menu:' . $value;
        }
        if ($kind === 'menu' && !str_starts_with($value, 'menu:')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: value of the menu setting. */ __('Invalid menu reference: %s. Use the menu slug, "principale" or "menu:principale" (the name of menus/<slug>.json or the slug given to menu-set).', 'nucleo'), $value));
        }

        $resolved = self::resolve($value, $id);

        return $kind === 'section' ? 'section:' . $resolved : $resolved;
    }

    /** @param callable(string $symbol): ?int $id */
    private static function resolve(string $symbol, callable $id): int
    {
        $resolved = $id($symbol);
        if ($resolved === null && str_starts_with($symbol, 'menu:')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: menu slug. */ __('Menu not found: %s. Create it with menus/<slug>.json or menu-set; nc/menu takes the slug ("principale" or "menu:principale").', 'nucleo'), substr($symbol, 5)));
        }
        if ($resolved === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: symbol name. */ __('Unresolved symbol: %s', 'nucleo'), $symbol));
        }

        return $resolved;
    }
}
