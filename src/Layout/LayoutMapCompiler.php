<?php

declare(strict_types=1);

namespace Nucleo\Layout;

defined('ABSPATH') || exit;

/**
 * Compiles layout documents into a lookup map (area → key → layouts) and an exclusion map.
 * Keys follow docs/contracts/layout-keys.md. Unknown conditions are ignored.
 * Ogni voce della mappa è [id, priorità, lingue]: lingue vuote vuol dire tutte le lingue.
 */
final class LayoutMapCompiler
{
    /**
     * @param list<array{id:int,area:string,priority:int,include:list<array>,exclude:list<array>}> $layouts
     * @param callable(string $kind, string $group, int $id): list<int> $descendants
     * @return array{
     *     map: array<string, array<string, list<array{0:int,1:int}|array{0:int,1:int,2:list<string>}>>>,
     *     exclude: array<string, array<string, list<int>>>,
     *     conflicts: list<array{area:string,key:string,layouts:list<int>}>
     * }
     */
    public function compile(array $layouts, callable $descendants): array
    {
        $map = [];
        $exclude = [];

        foreach ($layouts as $layout) {
            $area = $layout['area'];
            $id = $layout['id'];
            $langs = self::languagesOf($layout['include']);

            foreach ($layout['include'] as $condition) {
                foreach (self::keysOf($condition, $descendants) as $key) {
                    // Il terzo elemento (lingue) c'è solo se il layout ha condizioni di lingua.
                    $map[$area][$key][$id] = $langs === []
                        ? [$id, $layout['priority']]
                        : [$id, $layout['priority'], $langs];
                }
            }
            foreach ($layout['exclude'] as $condition) {
                foreach (self::keysOf($condition, $descendants) as $key) {
                    $exclude[$area][$key][$id] = $id;
                }
            }
        }

        $sorted = [];
        $conflicts = [];
        foreach ($map as $area => $keys) {
            foreach ($keys as $key => $entries) {
                $list = array_values($entries);
                // Priority descending, then id descending.
                usort($list, static fn (array $a, array $b): int => [$b[1], $b[0]] <=> [$a[1], $a[0]]);
                $sorted[$area][$key] = $list;

                $top = $list[0][1];
                $tied = array_values(array_filter($list, static fn (array $entry): bool => $entry[1] === $top));
                // Due layout in conflitto solo se possono valere nella stessa lingua.
                $clash = [];
                foreach ($tied as $a) {
                    foreach ($tied as $b) {
                        if ($a[0] !== $b[0] && self::overlaps($a[2] ?? [], $b[2] ?? [])) {
                            $clash[$a[0]] = $a[0];
                            $clash[$b[0]] = $b[0];
                        }
                    }
                }
                if (count($clash) > 1) {
                    $conflicts[] = ['area' => (string) $area, 'key' => (string) $key, 'layouts' => array_values($clash)];
                }
            }
        }

        $excludeLists = [];
        foreach ($exclude as $area => $keys) {
            foreach ($keys as $key => $ids) {
                $excludeLists[$area][$key] = array_values($ids);
            }
        }

        return ['map' => $sorted, 'exclude' => $excludeLists, 'conflicts' => $conflicts];
    }

    /**
     * Lingue dalle condizioni include di tipo language. Nessuna condizione: lista vuota.
     *
     * @param list<array<string, mixed>> $include
     * @return list<string>
     */
    private static function languagesOf(array $include): array
    {
        $langs = [];
        foreach ($include as $condition) {
            $value = $condition['value'] ?? null;
            if (($condition['type'] ?? null) === 'language' && is_string($value) && $value !== '') {
                $langs[] = $value;
            }
        }

        return array_values(array_unique($langs));
    }

    /**
     * Lista vuota = tutte le lingue, quindi si sovrappone a ogni lista.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function overlaps(array $a, array $b): bool
    {
        if ($a === [] || $b === []) {
            return true;
        }

        return array_intersect($a, $b) !== [];
    }

    /**
     * @param array<string, mixed> $condition
     * @return list<string>
     */
    private static function keysOf(array $condition, callable $descendants): array
    {
        $type = $condition['type'] ?? null;
        $value = $condition['value'] ?? null;
        $children = ($condition['children'] ?? false) === true;

        if ($type === 'entire_site') {
            return ['site'];
        }
        if (in_array($type, ['front', 'blog', 'search', '404'], true)) {
            return [$type];
        }
        if ($type === 'post_type' && is_string($value)) {
            return ["type:$value"];
        }
        if ($type === 'archive' && is_string($value)) {
            return ["archive:$value"];
        }
        if ($type === 'product_type' && is_string($value)) {
            return ["product_type:$value"];
        }
        if (!is_int($value)) {
            return [];
        }

        $keys = match ($type) {
            'post' => ["post:$value"],
            'parent' => array_values(array_map(
                static fn (int $d): string => "post:$d",
                $descendants('post', 'page', $value),
            )),
            'author' => ["author:$value"],
            'author_archive' => ["author_archive:$value"],
            'term' => self::termKeys('term', $condition['taxonomy'] ?? null, $children, $value, $descendants),
            'term_archive' => self::termKeys('term_archive', $condition['taxonomy'] ?? null, $children, $value, $descendants),
            'product_tag' => self::termKeys('term', 'product_tag', false, $value, $descendants),
            'product_attribute' => self::attributeKeys($condition['taxonomy'] ?? null, $value, $descendants),
            default => [],
        };

        return array_values($keys);
    }

    /**
     * Attributi di prodotto: solo tassonomie pa_<slug>.
     *
     * @return list<string>
     */
    private static function attributeKeys(mixed $taxonomy, int $id, callable $descendants): array
    {
        if (!is_string($taxonomy) || !str_starts_with($taxonomy, 'pa_')) {
            return [];
        }

        return self::termKeys('term', $taxonomy, false, $id, $descendants);
    }

    /** @return list<string> */
    private static function termKeys(string $prefix, mixed $taxonomy, bool $children, int $id, callable $descendants): array
    {
        if (!is_string($taxonomy)) {
            return [];
        }
        $keys = ["$prefix:$taxonomy:$id"];
        if ($children) {
            foreach ($descendants('term', $taxonomy, $id) as $d) {
                $keys[] = "$prefix:$taxonomy:$d";
            }
        }
        return $keys;
    }
}
