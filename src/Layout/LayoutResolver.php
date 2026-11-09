<?php

declare(strict_types=1);

namespace Nucleo\Layout;

/** Picks the layout for a request context, using the maps built by LayoutMapCompiler. */
final class LayoutResolver
{
    /**
     * @param array<string, array<string, list<array{0:int,1:int}|array{0:int,1:int,2:list<string>}>>> $map
     * @param array<string, array<string, list<int>>> $exclude
     */
    public function __construct(private readonly array $map, private readonly array $exclude)
    {
    }

    /**
     * Lookup keys for a request context, from most to least specific.
     *
     * @param array<string, mixed> $context
     * @return list<string>
     */
    public function keys(array $context): array
    {
        $kind = $context['kind'] ?? null;

        return match ($kind) {
            'singular' => self::singularKeys($context),
            'term_archive' => [
                'term_archive:' . ($context['taxonomy'] ?? '') . ':' . ($context['id'] ?? ''),
                'archive:' . ($context['taxonomy'] ?? ''),
                'site',
            ],
            'post_type_archive' => ['archive:' . ($context['type'] ?? ''), 'site'],
            'author_archive' => ['author_archive:' . ($context['id'] ?? ''), 'site'],
            'search', '404', 'blog' => [$kind, 'site'],
            default => ['site'],
        };
    }

    /**
     * Layouts excluded for this context, with the context key that excluded them.
     *
     * @param array<string, mixed> $context
     * @return list<array{layout:int,by:string}>
     */
    public function excluded(string $area, array $context): array
    {
        $out = [];
        foreach ($this->keys($context) as $key) {
            foreach ($this->exclude[$area][$key] ?? [] as $id) {
                $out[] = ['layout' => (int) $id, 'by' => $key];
            }
        }

        return $out;
    }

    /**
     * The most specific matching layout for an area, or null.
     * A layout excluded for any key of the context is skipped, and so is a layout
     * whose languages do not include the language of the context.
     * Among the term keys of a singular context the highest priority wins; on a tie the first key wins.
     *
     * @param array<string, mixed> $context
     * @return array{layout:int,matched:string}|null
     */
    public function resolve(string $area, array $context): ?array
    {
        $keys = $this->keys($context);

        $excluded = [];
        foreach ($keys as $key) {
            foreach ($this->exclude[$area][$key] ?? [] as $id) {
                $excluded[$id] = true;
            }
        }

        $best = null;
        foreach ($keys as $key) {
            $hit = $this->firstValid($area, $key, $excluded, $context);
            if ($hit === null) {
                continue;
            }
            if (!str_starts_with($key, 'term:')) {
                // Una chiave non di termine più specifica dei termini vince su di essi.
                $hit = $best ?? $hit;

                return ['layout' => $hit['layout'], 'matched' => $hit['matched']];
            }
            if ($best === null || $hit['priority'] > $best['priority']) {
                $best = $hit;
            }
        }

        return $best === null ? null : ['layout' => $best['layout'], 'matched' => $best['matched']];
    }

    /**
     * Prima voce valida di una chiave: non esclusa e nella lingua del contesto.
     *
     * @param array<int, true> $excluded
     * @param array<string, mixed> $context
     * @return array{layout:int,matched:string,priority:int}|null
     */
    private function firstValid(string $area, string $key, array $excluded, array $context): ?array
    {
        foreach ($this->map[$area][$key] ?? [] as $entry) {
            $id = $entry[0];
            if (!isset($excluded[$id]) && self::speaks($entry[2] ?? [], $context)) {
                return ['layout' => $id, 'matched' => $key, 'priority' => $entry[1]];
            }
        }

        return null;
    }

    /**
     * Una voce senza lingue vale sempre. Senza lingua nel contesto vale ogni voce.
     *
     * @param list<string> $langs
     * @param array<string, mixed> $context
     */
    private static function speaks(array $langs, array $context): bool
    {
        $language = $context['language'] ?? null;
        if ($langs === [] || !is_string($language) || $language === '') {
            return true;
        }

        return in_array($language, $langs, true);
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private static function singularKeys(array $context): array
    {
        $keys = [];
        if (!empty($context['front'])) {
            $keys[] = 'front';
        }
        $keys[] = 'post:' . ($context['id'] ?? '');
        foreach ($context['terms'] ?? [] as $taxonomy => $ids) {
            foreach ($ids as $termId) {
                $keys[] = "term:$taxonomy:$termId";
            }
        }
        if (isset($context['product_type'])) {
            $keys[] = 'product_type:' . $context['product_type'];
        }
        if (isset($context['author'])) {
            $keys[] = 'author:' . $context['author'];
        }
        $keys[] = 'type:' . ($context['type'] ?? '');
        $keys[] = 'site';

        return $keys;
    }
}
