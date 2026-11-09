<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

/**
 * Riferimenti interni nei campi link: "home", "page:<slug>", "post:<slug>", "<post-type>:<slug>",
 * "term:<tassonomia>/<slug>", con "#ancora" facoltativa. Il documento salvato conserva il simbolo;
 * resolve() lo sostituisce con l'URL prima della validazione e della compilazione.
 * Funzioni pure: il lookup su WordPress arriva come callable.
 */
final class LinkRefs
{
    /** Chiavi di content e settings che portano un link, a ogni profondità. */
    public const KEYS = ['href'];

    /** Valore che prende un riferimento senza contenuto: il compilatore lo stampa come href="#" con aria-disabled. */
    public const UNRESOLVED = '#nc-unresolved';

    /** Schemi di URL e nomi riservati: non sono riferimenti interni. */
    private const RESERVED = [
        'http', 'https', 'mailto', 'tel', 'sms', 'ftp', 'file', 'data',
        'javascript', 'vbscript', 'section', 'menu', 'media', 'author', 'term',
    ];

    private const TYPE = '[a-z0-9_-]{1,20}';
    private const SEGMENT = '[a-z0-9_%-]+';

    /**
     * @return array{kind:'home'|'post'|'term',type:string,slug:string,fragment:string}|null
     *         type: tipo di post ("page", "post", ...) o tassonomia; vuoto per home. null se il valore non è un riferimento.
     */
    public static function parse(string $value): ?array
    {
        $fragment = '';
        $base = $value;
        if (preg_match('/#([A-Za-z0-9_-]+)$/D', $value, $m) === 1) {
            $fragment = $m[1];
            $base = substr($value, 0, -strlen($m[0]));
        }

        if ($base === 'home') {
            return ['kind' => 'home', 'type' => '', 'slug' => '', 'fragment' => $fragment];
        }

        if (preg_match('/^term:(' . self::TYPE . ')[\/:](' . self::SEGMENT . ')$/D', $base, $m) === 1) {
            return ['kind' => 'term', 'type' => $m[1], 'slug' => $m[2], 'fragment' => $fragment];
        }

        $post = '/^(' . self::TYPE . '):(' . self::SEGMENT . '(?:\/' . self::SEGMENT . ')*)$/D';
        if (preg_match($post, $base, $m) !== 1 || in_array($m[1], self::RESERVED, true)) {
            return null;
        }

        return ['kind' => 'post', 'type' => $m[1], 'slug' => $m[2], 'fragment' => $fragment];
    }

    public static function isRef(string $value): bool
    {
        return self::parse($value) !== null;
    }

    /**
     * Dipendenza per un riferimento non risolto: "link-slug:<type>:<slug>" o "link-slug:term:<tassonomia>/<slug>".
     * @param array{kind:string,type:string,slug:string,fragment?:string} $ref
     */
    public static function slugDep(array $ref): string
    {
        if ($ref['kind'] === 'post') {
            return 'link-slug:' . $ref['type'] . ':' . $ref['slug'];
        }
        if ($ref['kind'] === 'term') {
            return 'link-slug:term:' . $ref['type'] . '/' . $ref['slug'];
        }
        return '';
    }

    /**
     * Sostituisce i riferimenti nei campi KEYS dei nodi e negli href dei tag <a> di content.html.
     *
     * @param array<string,mixed> $doc
     * @param callable(array{kind:string,type:string,slug:string,fragment:string}):?array{url:string,dep:?string} $lookup
     * @return array{doc:array<string,mixed>,deps:list<string>,missing:list<array{id:string,ref:string}>,paths:list<array{id:string,href:string}>}
     */
    public static function resolve(array $doc, callable $lookup): array
    {
        $deps = [];
        $missing = [];
        $paths = [];

        if (isset($doc['root']) && is_array($doc['root'])) {
            $doc['root'] = self::resolveNode($doc['root'], $lookup, $deps, $missing, $paths);
        }

        return [
            'doc' => $doc,
            'deps' => array_values(array_unique($deps)),
            'missing' => $missing,
            'paths' => $paths,
        ];
    }

    /**
     * @param array<string,mixed> $node
     * @param list<string> $deps
     * @param list<array{id:string,ref:string}> $missing
     * @param list<array{id:string,href:string}> $paths
     * @return array<string,mixed>
     */
    private static function resolveNode(array $node, callable $lookup, array &$deps, array &$missing, array &$paths): array
    {
        $id = (string) ($node['id'] ?? '');

        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = self::resolveTree($node['content'], $id, $lookup, $deps, $missing, $paths);
            if (isset($node['content']['html']) && is_string($node['content']['html'])) {
                $node['content']['html'] = self::resolveHtml($node['content']['html'], $id, $lookup, $deps, $missing, $paths);
            }
        }

        if (isset($node['settings']) && is_array($node['settings'])) {
            $node['settings'] = self::resolveTree($node['settings'], $id, $lookup, $deps, $missing, $paths);
        }

        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $i => $child) {
                if (is_array($child)) {
                    $node['children'][$i] = self::resolveNode($child, $lookup, $deps, $missing, $paths);
                }
            }
        }

        return $node;
    }

    /**
     * @param array<mixed> $tree
     * @param list<string> $deps
     * @param list<array{id:string,ref:string}> $missing
     * @param list<array{id:string,href:string}> $paths
     * @return array<mixed>
     */
    private static function resolveTree(array $tree, string $id, callable $lookup, array &$deps, array &$missing, array &$paths): array
    {
        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $tree[$key] = self::resolveTree($value, $id, $lookup, $deps, $missing, $paths);
            } elseif (is_string($value) && in_array($key, self::KEYS, true)) {
                $tree[$key] = self::resolveValue($value, $id, $lookup, $deps, $missing, $paths);
            }
        }

        return $tree;
    }

    /**
     * @param callable(array{kind:string,type:string,slug:string,fragment:string}):?array{url:string,dep:?string} $lookup
     * @param list<string> $deps
     * @param list<array{id:string,ref:string}> $missing
     * @param list<array{id:string,href:string}> $paths
     */
    private static function resolveValue(string $value, string $id, callable $lookup, array &$deps, array &$missing, array &$paths): string
    {
        $ref = self::parse($value);
        if ($ref !== null) {
            $hit = $lookup($ref);
            if ($hit !== null) {
                $value = $hit['url'] . ($ref['fragment'] !== '' ? '#' . $ref['fragment'] : '');
                if ($hit['dep'] !== null) {
                    $deps[] = $hit['dep'];
                }
            } else {
                $dep = self::slugDep($ref);
                if ($dep !== '') {
                    $deps[] = $dep;
                }
                $missing[] = ['id' => $id, 'ref' => $value];
                $value = self::UNRESOLVED;
            }
        }

        if (preg_match('#^/(?!/)#', $value) === 1) {
            $paths[] = ['id' => $id, 'href' => $value];
        }

        return $value;
    }

    /**
     * @param list<string> $deps
     * @param list<array{id:string,ref:string}> $missing
     * @param list<array{id:string,href:string}> $paths
     */
    private static function resolveHtml(string $html, string $id, callable $lookup, array &$deps, array &$missing, array &$paths): string
    {
        $out = preg_replace_callback(
            '/(<a\s(?:[^>]*\s)?href\s*=\s*)(["\'])(.*?)\2/is',
            static function (array $m) use ($id, $lookup, &$deps, &$missing, &$paths): string {
                $value = self::resolveValue($m[3], $id, $lookup, $deps, $missing, $paths);
                if ($value !== $m[3]) {
                    $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                }

                return $m[1] . $m[2] . $value . $m[2];
            },
            $html,
        );

        if ($out === null) {
            throw new \RuntimeException('content.html: cannot process the links');
        }

        return $out;
    }
}
