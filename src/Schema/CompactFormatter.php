<?php

declare(strict_types=1);

namespace Nucleo\Schema;

/**
 * Forma compatta del documento per l'AI: toglie le chiavi vuote e i settings uguali al default del blocco.
 * Con $nodeId restituisce solo quel sottoalbero. Con $depth i nodi a profondità >= $depth perdono children
 * e ricevono "childCount" (il nodo di partenza ha profondità 0).
 */
final class CompactFormatter
{
    /** Chiavi che si tolgono quando sono vuote. */
    private const EMPTY_KEYS = ['content', 'settings', 'style', 'visibility', 'bind'];

    /**
     * @param array<string,mixed> $doc
     * @param array<string,array<string,mixed>> $defaults tipo di blocco => ['settings' => [...]]
     * @return array<string,mixed>
     */
    public function compact(array $doc, ?string $nodeId = null, ?int $depth = null, array $defaults = []): array
    {
        if ($depth !== null && $depth < 0) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new PatchException(__('depth cannot be negative', 'nucleo'), -1, 'invalid_op');
        }
        $root = $doc['root'] ?? null;
        if ($nodeId !== null) {
            $node = is_array($root) ? $this->find($root, $nodeId) : null;
            if ($node === null) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new PatchException(sprintf(/* translators: %s: node id */ __('node not found: %s', 'nucleo'), $nodeId), -1, 'not_found');
            }
            return $this->node($node, 0, $depth, $defaults);
        }
        if (is_array($root)) {
            $doc['root'] = $this->node($root, 0, $depth, $defaults);
        }
        return $doc;
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,array<string,mixed>> $defaults
     * @return array<string,mixed>
     */
    private function node(array $node, int $level, ?int $depth, array $defaults): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            if ($key === 'children') {
                continue;
            }
            if ($key === 'settings' && is_array($value)) {
                $type = $node['type'] ?? null;
                $blockDefaults = is_string($type) && isset($defaults[$type]['settings'])
                    ? $defaults[$type]['settings']
                    : [];
                $value = $this->withoutDefaults($value, $blockDefaults);
            }
            if (in_array($key, self::EMPTY_KEYS, true) && $value === []) {
                continue;
            }
            $out[$key] = $value;
        }

        $children = $this->children($node);
        if ($children !== []) {
            if ($depth !== null && $level >= $depth) {
                $out['childCount'] = count($children);
            } else {
                $out['children'] = array_map(
                    fn (array $child): array => $this->node($child, $level + 1, $depth, $defaults),
                    $children,
                );
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $blockDefaults
     * @return array<string,mixed>
     */
    private function withoutDefaults(array $settings, array $blockDefaults): array
    {
        $kept = [];
        foreach ($settings as $key => $value) {
            if (array_key_exists($key, $blockDefaults) && $blockDefaults[$key] === $value) {
                continue;
            }
            $kept[$key] = $value;
        }
        return $kept;
    }

    /**
     * Figli del nodo che sono oggetti JSON, nell'ordine della lista.
     * @param array<string,mixed> $node
     * @return list<array<string,mixed>>
     */
    private function children(array $node): array
    {
        $raw = $node['children'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $child) {
            if (is_array($child)) {
                $out[] = $child;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>|null
     */
    private function find(array $node, string $id): ?array
    {
        if (($node['id'] ?? null) === $id) {
            return $node;
        }
        foreach ($this->children($node) as $child) {
            $found = $this->find($child, $id);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }
}
