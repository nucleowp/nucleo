<?php

declare(strict_types=1);

namespace Nucleo\Content;

defined('ABSPATH') || exit;

/** Differenze tra due modelli dei contenuti e modifiche distruttive (docs/contracts/content-model.md). */
final class ModelDiff
{
    /**
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @param array{postTypes?: array<string,int>, taxonomies?: array<string,int>, fields?: array<string,int>} $usage
     * @return array{added: list<string>, removed: list<string>, changed: list<string>, destructive: list<array{id: string, reason: string}>}
     */
    public static function between(array $old, array $new, array $usage): array
    {
        $before = self::items($old);
        $after = self::items($new);

        $added = [];
        $changed = [];
        foreach ($after as $id => $item) {
            if (!isset($before[$id])) {
                $added[] = $id;
            } elseif ($before[$id]['def'] !== $item['def']) {
                $changed[] = $id;
            }
        }

        $removed = [];
        $destructive = [];
        foreach ($before as $id => $item) {
            $saved = (int) ($usage[$item['bucket']][$item['name']] ?? 0);
            if (!isset($after[$id])) {
                $removed[] = $id;
                if ($saved > 0) {
                    $destructive[] = ['id' => $id, 'reason' => self::reason($item['bucket'], $saved)];
                }
            } elseif ($item['bucket'] === 'fields' && $item['def']['type'] !== $after[$id]['def']['type'] && $saved > 0) {
                $destructive[] = ['id' => $id, 'reason' => self::reason('typeChanged', $saved)];
            }
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed, 'destructive' => $destructive];
    }

    /**
     * Elementi del modello, in ordine: tipi, tassonomie, campi.
     *
     * @param array<string,mixed> $model
     * @return array<string,array{bucket: string, name: string, def: array<string,mixed>}>
     */
    private static function items(array $model): array
    {
        $items = [];
        foreach ($model['postTypes'] ?? [] as $postType) {
            $items['postType:' . $postType['slug']] = ['bucket' => 'postTypes', 'name' => $postType['slug'], 'def' => $postType];
        }
        foreach ($model['taxonomies'] ?? [] as $taxonomy) {
            $items['taxonomy:' . $taxonomy['slug']] = ['bucket' => 'taxonomies', 'name' => $taxonomy['slug'], 'def' => $taxonomy];
        }
        foreach ($model['fieldGroups'] ?? [] as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                $name = $group['id'] . '/' . $field['key'];
                $items['field:' . $name] = ['bucket' => 'fields', 'name' => $name, 'def' => $field];
            }
        }

        return $items;
    }

    private static function reason(string $bucket, int $saved): string
    {
        return match ($bucket) {
            'postTypes' => sprintf('post type has %d items', $saved),
            'taxonomies' => sprintf('taxonomy has %d terms', $saved),
            'typeChanged' => sprintf('field type changed, %d saved values', $saved),
            default => sprintf('field has %d saved values', $saved),
        };
    }
}
