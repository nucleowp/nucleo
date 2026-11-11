<?php

declare(strict_types=1);

namespace Nucleo\Schema;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Registry;

/** Completa un documento prima del rendering: schema mancante, default dei settings, riferimenti a sezioni. */
final class Normalizer
{
    private readonly Migrator $migrator;

    public function __construct(private readonly Registry $r)
    {
        $this->migrator = new Migrator($r);
    }

    /**
     * @param array<string,mixed> $doc
     * @param (callable(string): ?array<string,mixed>)|null $resolveRef riceve "section:N" e restituisce il documento referenziato
     * @return array<string,mixed>
     */
    public function normalize(array $doc, ?callable $resolveRef = null): array
    {
        if (!array_key_exists('schema', $doc)) {
            $doc['schema'] = 1;
        }

        $doc = $this->migrator->migrate($doc);

        if (is_array($doc['root'] ?? null)) {
            $doc['root'] = $this->node($doc['root'], $resolveRef);
        }

        return $doc;
    }

    /**
     * @param array<string,mixed> $node
     * @param (callable(string): ?array<string,mixed>)|null $resolveRef
     * @return array<string,mixed>
     */
    private function node(array $node, ?callable $resolveRef): array
    {
        if (is_string($node['ref'] ?? null)) {
            return $this->expandRef($node, $resolveRef);
        }

        $manifest = is_string($node['type'] ?? null) ? $this->r->get($node['type']) : null;
        if ($manifest !== null) {
            $node = $this->withDefaults($node, $manifest->settings);
        }

        if (is_array($node['children'] ?? null)) {
            $node['children'] = array_map(
                fn (mixed $child): mixed => is_array($child) ? $this->node($child, $resolveRef) : $child,
                $node['children'],
            );
        }

        return $node;
    }

    /**
     * Il nodo con ref diventa il root del documento referenziato, con l'id del nodo con ref.
     * Il documento referenziato viene normalizzato senza seguire altri ref, per evitare cicli.
     *
     * @param array<string,mixed> $node
     * @param (callable(string): ?array<string,mixed>)|null $resolveRef
     * @return array<string,mixed>
     */
    private function expandRef(array $node, ?callable $resolveRef): array
    {
        $ref = (string) $node['ref'];
        if ($resolveRef === null || preg_match('/^section:(\d+)$/', $ref, $m) !== 1) {
            return $node;
        }

        $doc = $resolveRef($ref);
        if (is_array($doc)) {
            $doc = $this->migrator->migrate($doc);
        }
        $root = is_array($doc) ? ($doc['root'] ?? null) : null;
        if (!is_array($root)) {
            return $node;
        }

        $expanded = $this->prefixIds($this->node($root, null), 'g' . $m[1]);
        if (array_key_exists('id', $node)) {
            $expanded['id'] = $node['id'];
        }

        return $expanded;
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,mixed> $settings dichiarazioni dei settings del manifest
     * @return array<string,mixed>
     */
    private function withDefaults(array $node, array $settings): array
    {
        $current = $node['settings'] ?? [];
        if (!is_array($current)) {
            return $node;
        }

        foreach ($settings as $name => $schema) {
            if (is_array($schema) && !array_key_exists((string) $name, $current) && array_key_exists('default', $schema)) {
                $current[(string) $name] = $schema['default'];
            }
        }

        if ($current !== []) {
            $node['settings'] = $current;
        }

        return $node;
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function prefixIds(array $node, string $prefix): array
    {
        if (is_string($node['id'] ?? null)) {
            $node['id'] = substr($prefix . $node['id'], 0, 12);
        }

        if (is_array($node['children'] ?? null)) {
            $node['children'] = array_map(
                fn (mixed $child): mixed => is_array($child) ? $this->prefixIds($child, $prefix) : $child,
                $node['children'],
            );
        }

        return $node;
    }
}
