<?php

declare(strict_types=1);

namespace Nucleo\Schema;

/**
 * Applica operazioni semantiche (per id) e JSON Patch (RFC 6902, path RFC 6901) a un documento.
 * È atomica: lavora su una copia, e su errore lancia PatchException lasciando intatto il documento di partenza.
 */
final class PatchEngine
{
    private const ID_PATTERN = '/^[a-z0-9]{1,12}$/';
    private const INDEX_PATTERN = '/^(0|[1-9][0-9]*)$/';
    private const POINTER_PATTERN = '#^(/([^/~]|~[01])*)*$#';
    private const POINTER_OPS = ['add', 'replace', 'test', 'copy'];
    private const PROTECTED_KEYS = ['id', 'children'];

    /**
     * @param array<string,mixed> $doc
     * @param list<array<string,mixed>> $ops
     * @return array<string,mixed>
     */
    public function apply(array $doc, array $ops): array
    {
        foreach (array_values($ops) as $i => $op) {
            $doc = $this->applyOne($doc, $op, $i);
        }
        return $doc;
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    private function applyOne(array $doc, array $op, int $i): array
    {
        $name = $op['op'] ?? null;
        if (!is_string($name)) {
            $this->fail(__('operation without op field', 'nucleo'), $i, 'invalid_op');
        }
        if (
            in_array($name, self::POINTER_OPS, true)
            || ($name === 'move' && array_key_exists('from', $op))
            || ($name === 'remove' && array_key_exists('path', $op))
        ) {
            return $this->applyPointer($doc, $op, $name, $i);
        }
        return match ($name) {
            'set' => $this->set($doc, $op, $i),
            'insert' => $this->insert($doc, $op, $i),
            'move' => $this->move($doc, $op, $i),
            'remove' => $this->remove($doc, $op, $i),
            'duplicate' => $this->duplicate($doc, $op, $i),
            'wrap' => $this->wrap($doc, $op, $i),
            default => $this->fail(sprintf(/* translators: %s: operation name */ __('unknown operation: %s', 'nucleo'), $name), $i, 'invalid_op'),
        };
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    private function set(array $doc, array $op, int $i): array
    {
        $id = $this->requireString($op, 'id', $i);
        $path = $this->requireString($op, 'path', $i);
        $value = $this->requireValue($op, $i);
        $segments = explode('.', $path);
        if (in_array($segments[0], self::PROTECTED_KEYS, true)) {
            $this->fail(sprintf(/* translators: %s: protected field name */ __('set cannot modify "%s"', 'nucleo'), $segments[0]), $i, 'forbidden');
        }
        if (in_array('', $segments, true)) {
            $this->fail(sprintf(/* translators: %s: invalid path */ __('invalid path: %s', 'nucleo'), $path), $i, 'invalid_op');
        }
        $at = $this->requireNode($doc, $id, $i);
        return $this->editAt(
            $doc,
            $at,
            fn (array $node): array => $this->setPath($node, $segments, $value, $i),
            $i,
        );
    }

    /**
     * @param list<string> $segments
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function setPath(array $node, array $segments, mixed $value, int $i): array
    {
        $key = $segments[0];
        if (count($segments) === 1) {
            if ($value === null) {
                unset($node[$key]);
            } else {
                $node[$key] = $value;
            }
            return $node;
        }
        $child = $node[$key] ?? null;
        if ($child === null) {
            if ($value === null) {
                return $node;
            }
            $child = [];
        }
        if (!is_array($child)) {
            $this->fail(sprintf(/* translators: %s: path segment */ __('the path goes through "%s", which is not an object', 'nucleo'), $key), $i, 'invalid_op');
        }
        $node[$key] = $this->setPath($child, array_slice($segments, 1), $value, $i);
        return $node;
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    private function insert(array $doc, array $op, int $i): array
    {
        $parentId = $this->requireString($op, 'parent', $i);
        $index = $this->optionalIndex($op, $i);
        $node = $op['node'] ?? null;
        if (!is_array($node)) {
            $this->fail(__('field "node" missing or invalid', 'nucleo'), $i, 'invalid_op');
        }
        $parentAt = $this->requireNode($doc, $parentId, $i);
        $this->claim($node, $this->docIds($doc), $i);
        return $this->editAt(
            $doc,
            $parentAt,
            fn (array $p): array => $this->withChild($p, $node, $index),
            $i,
        );
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    private function move(array $doc, array $op, int $i): array
    {
        $id = $this->requireString($op, 'id', $i);
        $parentId = $this->requireString($op, 'parent', $i);
        $index = $this->optionalIndex($op, $i);
        $at = $this->requireNonRoot($doc, $id, $i);
        $parentAt = $this->requireNode($doc, $parentId, $i);
        if (array_slice($parentAt, 0, count($at)) === $at) {
            $this->fail(sprintf(/* translators: %s: node id */ __('cannot move "%s" into itself or one of its descendants', 'nucleo'), $id), $i, 'forbidden');
        }
        $node = $this->nodeAt($doc, $at, $i);
        $doc = $this->detach($doc, $at, $i);
        $parentAt = $this->requireNode($doc, $parentId, $i);
        return $this->editAt(
            $doc,
            $parentAt,
            fn (array $p): array => $this->withChild($p, $node, $index),
            $i,
        );
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    private function remove(array $doc, array $op, int $i): array
    {
        $at = $this->requireNonRoot($doc, $this->requireString($op, 'id', $i), $i);
        return $this->detach($doc, $at, $i);
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    private function duplicate(array $doc, array $op, int $i): array
    {
        $at = $this->requireNonRoot($doc, $this->requireString($op, 'id', $i), $i);
        $index = $this->lastKey($at);
        $used = $this->docIds($doc);
        $clone = $this->reassign($this->nodeAt($doc, $at, $i), $used);
        return $this->editAt(
            $doc,
            $this->parentPath($at),
            fn (array $p): array => $this->withChild($p, $clone, $index + 1),
            $i,
        );
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    private function wrap(array $doc, array $op, int $i): array
    {
        $raw = $op['ids'] ?? null;
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            $this->fail(__('"ids" must be a list with at least one item', 'nucleo'), $i, 'invalid_op');
        }
        $ids = [];
        foreach ($raw as $id) {
            if (!is_string($id) || $id === '') {
                $this->fail(__('"ids" contains an invalid value', 'nucleo'), $i, 'invalid_op');
            }
            $ids[] = $id;
        }
        if (count(array_unique($ids)) !== count($ids)) {
            $this->fail(__('"ids" contains duplicates', 'nucleo'), $i, 'invalid_op');
        }
        $with = $op['with'] ?? null;
        if (!is_array($with)) {
            $this->fail(__('"with" must be an object', 'nucleo'), $i, 'invalid_op');
        }
        $type = $with['type'] ?? null;
        if (!is_string($type) || $type === '') {
            $this->fail(__('"with" must have a "type"', 'nucleo'), $i, 'invalid_op');
        }
        $parent = $this->parentPath($this->requireNonRoot($doc, $ids[0], $i));
        foreach (array_slice($ids, 1) as $id) {
            if ($this->parentPath($this->requireNonRoot($doc, $id, $i)) !== $parent) {
                $this->fail(__('the nodes to wrap must have the same parent', 'nucleo'), $i, 'invalid_op');
            }
        }
        $used = $this->docIds($doc);
        $wid = $with['id'] ?? null;
        if ($wid === null) {
            $wid = $this->freshId('w', $used);
        } elseif (!is_string($wid) || preg_match(self::ID_PATTERN, $wid) !== 1) {
            $this->fail(__('"with.id" is invalid', 'nucleo'), $i, 'invalid_op');
        } elseif (isset($used[$wid])) {
            $this->fail(sprintf(/* translators: %s: node id */ __('id already present: %s', 'nucleo'), $wid), $i, 'conflict');
        }
        return $this->editAt(
            $doc,
            $parent,
            fn (array $p): array => $this->wrapChildren($p, $ids, $with, $wid),
            $i,
        );
    }

    /**
     * @param array<string,mixed> $parent
     * @param list<string> $ids
     * @param array<string,mixed> $with
     * @return array<string,mixed>
     */
    private function wrapChildren(array $parent, array $ids, array $with, string $wid): array
    {
        $children = $this->children($parent);
        $moved = [];
        foreach ($children as $child) {
            if (in_array($this->idOf($child), $ids, true)) {
                $moved[] = $child;
            }
        }
        $wrapper = array_merge($with, ['id' => $wid, 'children' => $moved]);
        $out = [];
        $placed = false;
        foreach ($children as $child) {
            if (!in_array($this->idOf($child), $ids, true)) {
                $out[] = $child;
            } elseif (!$placed) {
                $out[] = $wrapper;
                $placed = true;
            }
        }
        $parent['children'] = $out;
        return $parent;
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $op
     * @return array<string,mixed>
     */
    private function applyPointer(array $doc, array $op, string $name, int $i): array
    {
        if ($name === 'copy' || $name === 'move') {
            $from = $this->pointer($op, 'from', $i);
            $path = $this->pointer($op, 'path', $i);
            if ($from === [] || $path === [] || ($name === 'move' && $this->isProperPrefix($from, $path))) {
                $this->fail(__('move or copy not allowed on the root or inside the source', 'nucleo'), $i, 'forbidden');
            }
            $value = $this->lookup($doc, $from, $i);
            if ($name === 'move') {
                $doc = $this->removeAt($doc, $from, $i);
            }
            return $this->addAt($doc, $path, $value, $i);
        }

        $path = $this->pointer($op, 'path', $i);
        if ($name === 'test') {
            try {
                $actual = $this->lookup($doc, $path, $i);
            } catch (PatchException) {
                $this->fail(__('test failed: path missing', 'nucleo'), $i, 'conflict');
            }
            if ($actual !== $this->requireValue($op, $i)) {
                $this->fail(__('test failed: different value', 'nucleo'), $i, 'conflict');
            }
            return $doc;
        }
        if ($path === []) {
            $this->fail(__('operation not allowed on the root', 'nucleo'), $i, 'forbidden');
        }
        return match ($name) {
            'add' => $this->addAt($doc, $path, $this->requireValue($op, $i), $i),
            'replace' => $this->replaceAt($doc, $path, $this->requireValue($op, $i), $i),
            'remove' => $this->removeAt($doc, $path, $i),
            default => $this->fail(sprintf(/* translators: %s: operation name */ __('unknown operation: %s', 'nucleo'), $name), $i, 'invalid_op'),
        };
    }

    /**
     * @param array<string,mixed> $doc
     * @param list<string> $path
     * @return array<string,mixed>
     */
    private function addAt(array $doc, array $path, mixed $value, int $i): array
    {
        [$parentPath, $key] = $this->splitPath($path);
        return $this->editAt($doc, $parentPath, function (array $c) use ($key, $value, $i): array {
            if (!$this->isListFor($c, $key)) {
                $c[$key] = $value;
                return $c;
            }
            if ($key === '-') {
                array_splice($c, count($c), 0, [$value]);
                return $c;
            }
            if (preg_match(self::INDEX_PATTERN, $key) !== 1 || (int) $key > count($c)) {
                $this->fail(sprintf(/* translators: %s: index */ __('invalid index: %s', 'nucleo'), $key), $i, 'invalid_op');
            }
            array_splice($c, (int) $key, 0, [$value]);
            return $c;
        }, $i);
    }

    /**
     * @param array<string,mixed> $doc
     * @param list<string> $path
     * @return array<string,mixed>
     */
    private function replaceAt(array $doc, array $path, mixed $value, int $i): array
    {
        [$parentPath, $key] = $this->splitPath($path);
        return $this->editAt($doc, $parentPath, function (array $c) use ($key, $value, $i): array {
            $k = $this->existingKey($c, $key, $i);
            $c[$k] = $value;
            return $c;
        }, $i);
    }

    /**
     * @param array<string,mixed> $doc
     * @param list<string> $path
     * @return array<string,mixed>
     */
    private function removeAt(array $doc, array $path, int $i): array
    {
        [$parentPath, $key] = $this->splitPath($path);
        return $this->editAt($doc, $parentPath, function (array $c) use ($key, $i): array {
            $k = $this->existingKey($c, $key, $i);
            if (is_int($k)) {
                array_splice($c, $k, 1);
            } else {
                unset($c[$k]);
            }
            return $c;
        }, $i);
    }

    /**
     * Percorso (chiavi) del nodo dentro il documento, es. ['root', 'children', '2'].
     * @param array<string,mixed> $doc
     * @return list<string>|null
     */
    private function locate(array $doc, string $id): ?array
    {
        $root = $doc['root'] ?? null;
        return is_array($root) ? $this->locateIn($root, $id, ['root']) : null;
    }

    /**
     * @param array<string,mixed> $node
     * @param list<string> $path
     * @return list<string>|null
     */
    private function locateIn(array $node, string $id, array $path): ?array
    {
        if ($this->idOf($node) === $id) {
            return $path;
        }
        foreach ($this->children($node) as $k => $child) {
            $found = $this->locateIn($child, $id, [...$path, 'children', (string) $k]);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $doc
     * @return list<string>
     */
    private function requireNode(array $doc, string $id, int $i): array
    {
        $at = $this->locate($doc, $id);
        if ($at === null) {
            $this->fail(sprintf(/* translators: %s: node id */ __('node not found: %s', 'nucleo'), $id), $i, 'not_found');
        }
        return $at;
    }

    /**
     * @param array<string,mixed> $doc
     * @return list<string>
     */
    private function requireNonRoot(array $doc, string $id, int $i): array
    {
        $at = $this->requireNode($doc, $id, $i);
        if ($at === ['root']) {
            $this->fail(__('operation not allowed on the root', 'nucleo'), $i, 'forbidden');
        }
        return $at;
    }

    /**
     * @param array<string,mixed> $doc
     * @param list<string> $path
     * @return array<string,mixed>
     */
    private function nodeAt(array $doc, array $path, int $i): array
    {
        $node = $this->lookup($doc, $path, $i);
        if (!is_array($node)) {
            $this->fail(__('the path does not point to a node', 'nucleo'), $i, 'not_found');
        }
        return $node;
    }

    /**
     * @param list<string> $path
     */
    private function lookup(mixed $node, array $path, int $i): mixed
    {
        foreach ($path as $key) {
            if (!is_array($node)) {
                $this->fail(sprintf(/* translators: %s: path segment */ __('path missing: %s', 'nucleo'), $key), $i, 'not_found');
            }
            $node = $node[$this->existingKey($node, $key, $i)];
        }
        return $node;
    }

    /**
     * Applica $fn al contenitore in fondo al percorso, ricostruendo il documento.
     * @param array<string,mixed> $node
     * @param list<string> $path
     * @param callable(array<mixed,mixed>): array<mixed,mixed> $fn
     * @return array<string,mixed>
     */
    private function editAt(array $node, array $path, callable $fn, int $i): array
    {
        if ($path === []) {
            return $fn($node);
        }
        $head = $path[0];
        $k = $this->existingKey($node, $head, $i);
        $child = $node[$k] ?? null;
        if (!is_array($child)) {
            $this->fail(sprintf(/* translators: %s: path segment */ __('the path goes through "%s", which is not an object', 'nucleo'), $head), $i, 'not_found');
        }
        $node[$k] = $this->editAt($child, array_slice($path, 1), $fn, $i);
        return $node;
    }

    /**
     * Chiave esistente in $c: indice se $c è una lista, altrimenti chiave dell'oggetto.
     * @param array<array-key,mixed> $c
     * @return int|string
     */
    private function existingKey(array $c, string $key, int $i): int|string
    {
        if ($c !== [] && array_is_list($c)) {
            if (preg_match(self::INDEX_PATTERN, $key) !== 1) {
                $this->fail(sprintf(/* translators: %s: list index */ __('invalid list index: %s', 'nucleo'), $key), $i, 'invalid_op');
            }
            $index = (int) $key;
            if (!array_key_exists($index, $c)) {
                $this->fail(sprintf(/* translators: %s: list index */ __('index outside the list: %s', 'nucleo'), $key), $i, 'not_found');
            }
            return $index;
        }
        if (!array_key_exists($key, $c)) {
            $this->fail(sprintf(/* translators: %s: key name */ __('key missing: %s', 'nucleo'), $key), $i, 'not_found');
        }
        return $key;
    }

    /** @param array<array-key,mixed> $c */
    private function isListFor(array $c, string $key): bool
    {
        if ($c === []) {
            return $key === '-' || preg_match(self::INDEX_PATTERN, $key) === 1;
        }
        return array_is_list($c);
    }

    /**
     * @param array<string,mixed> $op
     * @return list<string>
     */
    private function pointer(array $op, string $key, int $i): array
    {
        $ptr = $op[$key] ?? null;
        if (!is_string($ptr) || preg_match(self::POINTER_PATTERN, $ptr) !== 1) {
            $this->fail(sprintf(/* translators: %s: field name */ __('invalid pointer in "%s"', 'nucleo'), $key), $i, 'invalid_op');
        }
        if ($ptr === '') {
            return [];
        }
        $tokens = [];
        foreach (explode('/', substr($ptr, 1)) as $token) {
            $tokens[] = str_replace(['~1', '~0'], ['/', '~'], $token);
        }
        return $tokens;
    }

    /**
     * Separa l'ultima chiave dal resto del percorso. Il percorso deve essere non vuoto.
     * @param list<string> $path
     * @return array{list<string>, string}
     */
    private function splitPath(array $path): array
    {
        return [array_slice($path, 0, -1), $path[count($path) - 1] ?? ''];
    }

    /**
     * @param list<string> $prefix
     * @param list<string> $list
     */
    private function isProperPrefix(array $prefix, array $list): bool
    {
        return count($prefix) < count($list) && array_slice($list, 0, count($prefix)) === $prefix;
    }

    /** @param array<string,mixed> $op */
    private function requireValue(array $op, int $i): mixed
    {
        if (!array_key_exists('value', $op)) {
            $this->fail(__('field "value" missing', 'nucleo'), $i, 'invalid_op');
        }
        return $op['value'];
    }

    /** @param array<string,mixed> $op */
    private function requireString(array $op, string $key, int $i): string
    {
        $value = $op[$key] ?? null;
        if (!is_string($value) || $value === '') {
            $this->fail(sprintf(/* translators: %s: field name */ __('field "%s" missing or invalid', 'nucleo'), $key), $i, 'invalid_op');
        }
        return $value;
    }

    /** @param array<string,mixed> $op */
    private function optionalIndex(array $op, int $i): ?int
    {
        if (!array_key_exists('index', $op)) {
            return null;
        }
        $index = $op['index'];
        if (!is_int($index) || $index < 0) {
            $this->fail(__('"index" must be an integer greater than or equal to 0', 'nucleo'), $i, 'invalid_op');
        }
        return $index;
    }

    /**
     * Rimuove il nodo in $at dal suo parent.
     * @param array<string,mixed> $doc
     * @param list<string> $at
     * @return array<string,mixed>
     */
    private function detach(array $doc, array $at, int $i): array
    {
        $idx = $this->lastKey($at);
        return $this->editAt($doc, $this->parentPath($at), fn (array $p): array => $this->withoutChild($p, $idx), $i);
    }

    /**
     * Percorso del parent di un nodo: il nodo sta in <parent>['children'][k].
     * @param list<string> $at
     * @return list<string>
     */
    private function parentPath(array $at): array
    {
        return array_slice($at, 0, -2);
    }

    /**
     * Indice del nodo nella lista children del suo parent.
     * @param list<string> $at
     */
    private function lastKey(array $at): int
    {
        return (int) ($at[count($at) - 1] ?? 0);
    }

    /**
     * @param array<string,mixed> $parent
     * @param array<string,mixed> $child
     * @return array<string,mixed>
     */
    private function withChild(array $parent, array $child, ?int $index): array
    {
        $children = $this->children($parent);
        $at = $index === null ? count($children) : min($index, count($children));
        array_splice($children, $at, 0, [$child]);
        $parent['children'] = $children;
        return $parent;
    }

    /**
     * @param array<string,mixed> $parent
     * @return array<string,mixed>
     */
    private function withoutChild(array $parent, int $idx): array
    {
        $children = $this->children($parent);
        array_splice($children, $idx, 1);
        $parent['children'] = $children;
        return $parent;
    }

    /**
     * Figli del nodo: solo quelli che sono oggetti JSON, nell'ordine della lista.
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

    /** @param array<string,mixed> $node */
    private function idOf(array $node): ?string
    {
        $id = $node['id'] ?? null;
        return is_string($id) ? $id : null;
    }

    /**
     * Ids già presenti nel documento.
     * @param array<string,mixed> $doc
     * @return array<string,true>
     */
    private function docIds(array $doc): array
    {
        $root = $doc['root'] ?? null;
        return is_array($root) ? $this->idsOf($root, []) : [];
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,true> $ids
     * @return array<string,true>
     */
    private function idsOf(array $node, array $ids): array
    {
        $id = $this->idOf($node);
        if ($id !== null) {
            $ids[$id] = true;
        }
        foreach ($this->children($node) as $child) {
            $ids = $this->idsOf($child, $ids);
        }
        return $ids;
    }

    /**
     * Verifica un sottoalbero da inserire: id validi, type presente, nessun id già usato.
     * @param array<string,mixed> $node
     * @param array<string,true> $used
     * @return array<string,true>
     */
    private function claim(array $node, array $used, int $i): array
    {
        $id = $this->idOf($node);
        if ($id === null || preg_match(self::ID_PATTERN, $id) !== 1) {
            $this->fail(__('node id missing or invalid', 'nucleo'), $i, 'invalid_op');
        }
        $type = $node['type'] ?? null;
        if (!is_string($type) || $type === '') {
            $this->fail(sprintf(/* translators: %s: node id */ __('type missing on node "%s"', 'nucleo'), $id), $i, 'invalid_op');
        }
        if (isset($used[$id])) {
            $this->fail(sprintf(/* translators: %s: node id */ __('id already present: %s', 'nucleo'), $id), $i, 'conflict');
        }
        $used[$id] = true;
        $raw = $node['children'] ?? [];
        if (!is_array($raw)) {
            $this->fail(sprintf(/* translators: %s: node id */ __('children of node "%s" are invalid', 'nucleo'), $id), $i, 'invalid_op');
        }
        foreach ($raw as $child) {
            if (!is_array($child)) {
                $this->fail(sprintf(/* translators: %s: node id */ __('child of node "%s" is invalid', 'nucleo'), $id), $i, 'invalid_op');
            }
            $used = $this->claim($child, $used, $i);
        }
        return $used;
    }

    /**
     * Copia profonda con nuovi id per nodo e discendenti.
     * @param array<string,mixed> $node
     * @param array<string,true> $used
     * @return array<string,mixed>
     */
    private function reassign(array $node, array &$used): array
    {
        $node['id'] = $this->freshId($this->idOf($node) ?? '', $used);
        $children = [];
        foreach ($this->children($node) as $child) {
            $children[] = $this->reassign($child, $used);
        }
        if (array_key_exists('children', $node)) {
            $node['children'] = $children;
        }
        return $node;
    }

    /**
     * Primo id libero base + "c" + n (n >= 1) di al massimo 12 caratteri; altrimenti base36 del crc32.
     * @param array<string,true> $used
     */
    private function freshId(string $base, array &$used): string
    {
        for ($n = 1; strlen($base . 'c' . $n) <= 12; $n++) {
            $candidate = $base . 'c' . $n;
            if (!isset($used[$candidate])) {
                $used[$candidate] = true;
                return $candidate;
            }
        }
        for ($n = 1; $n < PHP_INT_MAX; $n++) {
            $candidate = base_convert((string) crc32($base . ':' . $n), 10, 36);
            if (!isset($used[$candidate])) {
                $used[$candidate] = true;
                return $candidate;
            }
        }
        $this->fail(__('cannot generate a free id', 'nucleo'), 0, 'conflict');
    }

    private function fail(string $message, int $index, string $code): never
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
        throw new PatchException($message, $index, $code);
    }
}
