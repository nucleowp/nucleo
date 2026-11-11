<?php

declare(strict_types=1);

namespace Nucleo\Schema;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Registry;
use Nucleo\Css\CustomRules;
use RuntimeException;

/**
 * Porta i nodi del documento alla versione corrente dei loro blocchi, applicando le migrazioni
 * dichiarate in block.nucleo.json (campo migrations: versione di arrivo → operazioni).
 */
final class Migrator
{
    /**
     * Migrazioni dello schema del documento, per versione di destinazione.
     * Vuote finché il campo schema resta 1.
     *
     * @var array<int,list<array<string,mixed>>>
     */
    public const SCHEMA_MIGRATIONS = [];

    /** @var array<string,array<int,list<array<string,mixed>>>> migrazioni lette, per cartella del blocco */
    private array $cache = [];

    public function __construct(private readonly Registry $r)
    {
    }

    /**
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    public function migrate(array $doc): array
    {
        if (!is_array($doc['root'] ?? null)) {
            return $doc;
        }

        $saved = is_array($doc['blocks'] ?? null) ? $doc['blocks'] : [];
        $types = [];
        $doc['root'] = $this->node($doc['root'], $saved, $types);

        foreach ($types as $type => $version) {
            $saved[$type] = $version;
        }
        if ($saved !== []) {
            $doc['blocks'] = $saved;
        }

        return $doc;
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,mixed> $saved versioni salvate nel documento, per tipo di blocco
     * @param array<string,int> $types versione corrente dei tipi presenti nel documento
     * @return array<string,mixed>
     */
    private function node(array $node, array $saved, array &$types): array
    {
        $manifest = is_string($node['type'] ?? null) ? $this->r->get($node['type']) : null;
        if ($manifest !== null) {
            $types[$manifest->name] = $manifest->version;

            $from = $saved[$manifest->name] ?? null;
            if (is_int($from) && $from < $manifest->version) {
                $node = $this->apply($node, $this->migrationsFor($manifest->dir), $from, $manifest->version);
            }
        }

        // style.custom nella forma vecchia (selettore → dichiarazioni) diventa la lista di regole strutturate.
        $custom = is_array($node['style'] ?? null) ? ($node['style']['custom'] ?? null) : null;
        if (is_array($custom) && $custom !== [] && !array_is_list($custom)) {
            $node['style']['custom'] = CustomRules::fromLegacy($custom);
        }

        if (is_array($node['children'] ?? null)) {
            foreach ($node['children'] as $i => $child) {
                if (is_array($child)) {
                    $node['children'][$i] = $this->node($child, $saved, $types);
                }
            }
        }

        return $node;
    }

    /**
     * Applica in ordine numerico le migrazioni con versione > $from e <= $to.
     *
     * @param array<string,mixed> $node
     * @param array<int,list<array<string,mixed>>> $migrations
     * @return array<string,mixed>
     */
    private function apply(array $node, array $migrations, int $from, int $to): array
    {
        ksort($migrations, SORT_NUMERIC);
        foreach ($migrations as $version => $ops) {
            if ($version <= $from || $version > $to) {
                continue;
            }
            foreach ($ops as $op) {
                $node = $this->run($node, $op);
            }
        }

        return $node;
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    private function run(array $node, array $op): array
    {
        $kind = $op['op'] ?? null;

        if ($kind === 'rename') {
            $from = $op['from'] ?? null;
            $to = $op['to'] ?? null;
            if (is_string($from) && is_string($to) && $this->has($node, $from) && !$this->has($node, $to)) {
                $value = $this->get($node, $from);

                return $this->set($this->remove($node, $from), $to, $value);
            }

            return $node;
        }

        if ($kind === 'remove' && is_string($op['path'] ?? null)) {
            return $this->remove($node, $op['path']);
        }

        if ($kind === 'set' && is_string($op['path'] ?? null) && array_key_exists('value', $op) && !$this->has($node, $op['path'])) {
            return $this->set($node, $op['path'], $op['value']);
        }

        return $node;
    }

    /** @return array<int,list<array<string,mixed>>> */
    private function migrationsFor(string $dir): array
    {
        if (!isset($this->cache[$dir])) {
            $file = $dir . '/block.nucleo.json';
            $raw = is_file($file) ? file_get_contents($file) : false;
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new RuntimeException(sprintf(/* translators: %s: manifest file path */ __('Manifest is not readable: %s', 'nucleo'), $file));
            }

            $list = is_array($decoded['migrations'] ?? null) ? $decoded['migrations'] : [];
            $out = [];
            foreach ($list as $version => $ops) {
                if (is_int($version) && is_array($ops)) {
                    $out[$version] = array_values(array_filter($ops, 'is_array'));
                }
            }
            $this->cache[$dir] = $out;
        }

        return $this->cache[$dir];
    }

    /** @param array<string,mixed> $node */
    private function has(array $node, string $path): bool
    {
        $cur = $node;
        foreach (explode('.', $path) as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                return false;
            }
            $cur = $cur[$key];
        }

        return true;
    }

    /** @param array<string,mixed> $node */
    private function get(array $node, string $path): mixed
    {
        $cur = $node;
        foreach (explode('.', $path) as $key) {
            $cur = is_array($cur) ? ($cur[$key] ?? null) : null;
        }

        return $cur;
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function set(array $node, string $path, mixed $value): array
    {
        [$head, $rest] = $this->split($path);
        if ($rest === null) {
            $node[$head] = $value;

            return $node;
        }

        $child = is_array($node[$head] ?? null) ? $node[$head] : [];
        $node[$head] = $this->set($child, $rest, $value);

        return $node;
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function remove(array $node, string $path): array
    {
        [$head, $rest] = $this->split($path);
        if (!array_key_exists($head, $node)) {
            return $node;
        }
        if ($rest === null) {
            unset($node[$head]);

            return $node;
        }
        if (is_array($node[$head])) {
            $node[$head] = $this->remove($node[$head], $rest);
        }

        return $node;
    }

    /** @return array{0:string,1:?string} */
    private function split(string $path): array
    {
        $parts = explode('.', $path, 2);

        return [$parts[0], $parts[1] ?? null];
    }
}
