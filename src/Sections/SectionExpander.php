<?php

declare(strict_types=1);

namespace Nucleo\Sections;

use Nucleo\Schema\ValidationError;

/**
 * Espande i nodi nc/section-ref nel root della sezione referenziata, ricorsivamente.
 * $find(slug) restituisce ['id' => int, 'doc' => array] oppure null se la sezione è assente o nel cestino.
 */
final class SectionExpander
{
    public const TYPE = 'nc/section-ref';
    public const MAX_DEPTH = 4;

    /** Riferimento a un parametro color nello stile: "$param.sfondo". */
    private const PARAM_PREFIX = '$param.';

    /** Riferimento a un parametro dentro un valore di stile: "$param.colore" anche in "1px solid $param.colore". */
    private const PARAM_REF = '/\$param\.([A-Za-z0-9_]+)/';

    /** Tipi il cui valore vuoto toglie il nodo con visible. */
    private const EMPTY_HIDES = ['text', 'html', 'link', 'image'];

    /** @var callable(string): ?array{id:int,doc:array<string,mixed>} */
    private $find;

    /** @param callable(string): ?array{id:int,doc:array<string,mixed>} $find */
    public function __construct(callable $find)
    {
        $this->find = $find;
    }

    /**
     * @param array<string,mixed> $doc
     * @param string|null $self slug della sezione che si sta modificando (per il rilevamento dei cicli)
     */
    public function expand(array $doc, ?string $self = null): Expansion
    {
        $st = ['errors' => [], 'missing' => [], 'deps' => [], 'origins' => [], 'bound' => [], 'found' => []];
        $ctx = [
            'scope' => null,
            'types' => [],
            'refId' => null,
            'topId' => null,
            'override' => null,
            'depth' => 0,
            'chain' => $self !== null ? [$self] : [],
        ];

        if (is_array($doc['root'] ?? null)) {
            if (($doc['kind'] ?? null) === 'section') {
                // Documento sezione salvato da solo: nessun valore passato, il required non è un errore qui.
                $decls = $this->declared($doc['params'] ?? [], null, $st);
                $ctx['scope'] = $this->resolveValues($decls, [], '/params', null, false, $st);
                $ctx['types'] = array_map(static fn (array $d): string => $d['type'], $decls);
            }
            $root = $this->processNode($doc['root'], '/root', $ctx, $st, true);
            if ($root !== null) {
                $doc['root'] = $root;
            }
        }

        return new Expansion(
            $doc,
            $st['errors'],
            $st['missing'],
            array_values(array_unique($st['deps'])),
            $st['origins'],
            $st['bound'],
        );
    }

    /**
     * Elabora un nodo di un documento o di una sezione: bind param, id, ref, figli.
     * Ritorna null se il nodo va tolto (visible falso). Il root non si toglie mai.
     * $forcedId è l'id già assegnato a una copia del bind "repeat".
     *
     * @param array<string,mixed> $node
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $st
     * @return array<string,mixed>|null
     */
    private function processNode(array $node, string $path, array $ctx, array &$st, bool $isRoot = false, ?string $forcedId = null): ?array
    {
        // Il vecchio campo 'ref' non si tocca fuori da una sezione riusabile.
        if (array_key_exists('ref', $node) && ($node['type'] ?? null) !== self::TYPE) {
            if ($ctx['refId'] !== null) {
                // Dentro una sezione espansa il nodo viene tolto in silenzio. Il root non si toglie mai.
                return $isRoot ? $node : null;
            }
            if ($ctx['scope'] !== null) {
                $this->err($st, $ctx['override'], $path . '/ref', __('legacy ref not supported inside a reusable section: use nc/section-ref', 'nucleo'));
            }

            return $node;
        }

        $origId = $this->idOf($node, $path);
        $id = $forcedId ?? $this->expandedId($ctx, $isRoot, $origId);
        $hidden = false;
        if (is_array($node['bind'] ?? null)) {
            $rest = [];
            foreach ($node['bind'] as $key => $bind) {
                $key = (string) $key;
                if ($key === 'repeat') {
                    // Il repeat lo elabora processChild sui figli: qui arriva solo dal root, dove non è ammesso.
                    if ($isRoot) {
                        $this->err($st, $ctx['override'], $path . '/bind/' . $key, __('repeat bind not allowed on the root of a section', 'nucleo'));
                    }
                    continue;
                }
                if (is_array($bind) && ($bind['source'] ?? null) === 'param') {
                    if ($ctx['scope'] === null) {
                        // Un bind param fuori da una sezione riusabile non ha valori: errore e bind tolto.
                        $this->err($st, $ctx['override'], $path . '/bind/' . $key, __('parameter bind outside a reusable section', 'nucleo'));
                        continue;
                    }
                    if ($isRoot && $key === 'visible') {
                        // Il root di una sezione non si nasconde: errore e bind tolto.
                        $this->err($st, $ctx['override'], $path . '/bind/' . $key, __('visible bind not allowed on the root of a section', 'nucleo'));
                        continue;
                    }
                    $hidden = $this->applyParamBind($node, $key, $bind, $path, $ctx, $st, $id) || $hidden;
                    continue;
                }
                $rest[$key] = $bind;
            }
            if ($rest === []) {
                unset($node['bind']);
            } else {
                $node['bind'] = $rest;
            }
        }

        if (is_array($node['style'] ?? null)) {
            $node['style'] = $this->styleParams($node['style'], $path, $ctx, $st, $id);
            if ($node['style'] === []) {
                unset($node['style']);
            }
        }

        if ($hidden && !$isRoot) {
            return null;
        }

        if ($id !== null) {
            $node['id'] = $id;
            $st['origins'][$id] = $ctx['topId'];
        }

        if (($node['type'] ?? null) === self::TYPE) {
            return $this->expandRef($node, $path, $ctx, $st);
        }

        if (is_array($node['children'] ?? null)) {
            $children = [];
            foreach ($node['children'] as $i => $child) {
                if (!is_array($child)) {
                    $children[] = $child;
                    continue;
                }
                foreach ($this->processChild($child, $path . '/children/' . $i, $ctx, $st) as $res) {
                    $children[] = $res;
                }
            }
            $node['children'] = $children;
        }

        return $node;
    }

    /**
     * Elabora un figlio. Con un bind "repeat" ritorna una copia per voce della lista, nello stesso genitore e nello stesso ordine.
     * Lista vuota o assente: nessuna copia, il nodo va tolto.
     *
     * @param array<string,mixed> $child
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $st
     * @return list<array<string,mixed>>
     */
    private function processChild(array $child, string $path, array $ctx, array &$st): array
    {
        $bind = is_array($child['bind'] ?? null) ? $child['bind'] : [];
        if (!array_key_exists('repeat', $bind)) {
            $res = $this->processNode($child, $path, $ctx, $st);

            return $res === null ? [] : [$res];
        }

        $items = $this->repeatItems($bind['repeat'], $path, $ctx, $st);
        unset($bind['repeat']);
        if ($bind === []) {
            unset($child['bind']);
        } else {
            $child['bind'] = $bind;
        }
        if ($items === null) {
            // Bind sbagliato: l'errore è già registrato e il nodo resta una volta, senza repeat.
            $res = $this->processNode($child, $path, $ctx, $st);

            return $res === null ? [] : [$res];
        }

        $origId = $this->idOf($child, $path);
        $out = [];
        foreach ($items as $index => $voce) {
            $cloneId = $this->cloneId($origId, $index, $ctx, $st);
            // Nel clone e nei discendenti "item" vale la voce; ogni clone ha il suo refId, così gli id dei discendenti restano unici.
            $cloneCtx = $ctx;
            $cloneCtx['scope']['item'] = $voce;
            $cloneCtx['types']['item'] = 'text';
            $cloneCtx['refId'] = $cloneId;
            $cloneCtx['repeated'] = true;
            $res = $this->processNode($child, $path, $cloneCtx, $st, false, $cloneId);
            if ($res !== null) {
                $out[] = $res;
            }
        }

        return $out;
    }

    /**
     * Voci del bind "repeat": lista vuota se il parametro non ha valore; null con un errore se il bind non è valido.
     *
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $st
     * @return list<string>|null
     */
    private function repeatItems(mixed $bind, string $path, array $ctx, array &$st): ?array
    {
        $at = $path . '/bind/repeat';
        if (!is_array($bind) || ($bind['source'] ?? null) !== 'param') {
            $this->err($st, $ctx['override'], $at, __('repeat bind needs source param', 'nucleo'));

            return null;
        }
        if ($ctx['scope'] === null) {
            $this->err($st, $ctx['override'], $at, __('parameter bind outside a reusable section', 'nucleo'));

            return null;
        }
        $field = $bind['field'] ?? null;
        if (!is_string($field) || $field === '') {
            $this->err($st, $ctx['override'], $at, __('bind field missing', 'nucleo'));

            return null;
        }
        // Accetta "param.<lista>" oltre al nome nudo, a meno che "param" non sia un parametro dichiarato.
        if (str_starts_with($field, 'param.') && !array_key_exists('param', $ctx['scope'])) {
            $field = substr($field, 6);
        }
        if (!array_key_exists($field, $ctx['scope'])) {
            $this->err($st, $ctx['override'], $at, sprintf(/* translators: %s: parameter name. */ __('undeclared parameter: %s', 'nucleo'), $field), $field, array_keys($ctx['scope']));

            return null;
        }
        if (($ctx['types'][$field] ?? null) !== 'list') {
            $this->err($st, $ctx['override'], $at, __('repeat only on list parameters', 'nucleo'), $field, [], __('declare the parameter with type list', 'nucleo'));

            return null;
        }
        $value = $ctx['scope'][$field];

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Id di una copia del bind "repeat": base del nodo senza caratteri non ammessi, troncata, con l'indice in coda.
     * Se l'id è già assegnato nell'espansione usa un hash, così resta unico.
     *
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $st
     */
    private function cloneId(string $origId, int $index, array $ctx, array &$st): string
    {
        $suffix = (string) $index;
        $base = substr((string) preg_replace('/[^a-z0-9]/', '', strtolower($origId)), 0, 12 - strlen($suffix));
        $id = ($base !== '' ? $base : 'n') . $suffix;
        if (array_key_exists($id, $st['origins'])) {
            $id = substr(sha1(($ctx['refId'] ?? '') . '/' . $origId . '/' . $index), 0, 12);
        }

        return $id;
    }

    /**
     * Espande un nodo nc/section-ref. Il nodo ha già bind e id risolti nel contesto esterno.
     *
     * @param array<string,mixed> $node
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $st
     * @return array<string,mixed>
     */
    private function expandRef(array $node, string $path, array $ctx, array &$st): array
    {
        $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
        $slug = $settings['section'] ?? null;
        $at = $path . '/settings/section';
        if (!is_string($slug) || $slug === '') {
            $this->err($st, $ctx['override'], $at, __('section slug missing', 'nucleo'));
            return $node;
        }

        $nodeId = $this->idOf($node, $path);
        // Il ciclo si controlla prima della ricerca: la sezione in creazione non è ancora nel database.
        if (in_array($slug, $ctx['chain'], true)) {
            $this->err($st, $ctx['override'], $at, sprintf(/* translators: %s: section slug. */ __('section loop: %s', 'nucleo'), $slug));
            return $node;
        }
        // Ogni slug si risolve una sola volta per expand(), anche se citato più volte.
        if (!array_key_exists($slug, $st['found'])) {
            $st['found'][$slug] = ($this->find)($slug);
        }
        $found = $st['found'][$slug];
        if ($found === null) {
            $st['deps'][] = 'section-slug:' . $slug;
            $st['missing'][] = ['id' => $ctx['topId'] ?? $nodeId, 'section' => $slug];
            return $node;
        }
        $st['deps'][] = 'section:' . $found['id'];
        $st['deps'][] = 'section-slug:' . $slug;

        $depth = $ctx['depth'] + 1;
        if ($depth > self::MAX_DEPTH) {
            $this->err($st, $ctx['override'], $at, sprintf(/* translators: %d: maximum depth. */ __('too many nested sections (maximum %d)', 'nucleo'), self::MAX_DEPTH));
            return $node;
        }

        $root = $found['doc']['root'] ?? null;
        if (!is_array($root)) {
            $this->err($st, $ctx['override'], $at, __('section has no root', 'nucleo'));
            return $node;
        }

        // Errori e mancanze dentro una sezione annidata finiscono sul nodo ref del documento esterno.
        $override = $ctx['override'] ?? $at;
        $decls = $this->declared($found['doc']['params'] ?? [], $override, $st);
        $given = is_array($settings['params'] ?? null) ? $settings['params'] : [];
        $values = $this->resolveValues($decls, $given, $path . '/settings/params', $ctx['override'], true, $st);

        $topId = $ctx['topId'] ?? $nodeId;
        $st['origins'][$nodeId] = $topId;
        $inner = [
            'scope' => $values,
            'types' => array_map(static fn (array $d): string => $d['type'], $decls),
            'refId' => $nodeId,
            'topId' => $topId,
            'override' => $override,
            'depth' => $depth,
            'chain' => [...$ctx['chain'], $slug],
        ];
        $expanded = $this->processNode($root, '/root', $inner, $st, true) ?? $node;
        if (array_key_exists('visibility', $node)) {
            $expanded['visibility'] = $node['visibility'];
        }

        return $expanded;
    }

    /**
     * Applica un bind "param" a un nodo. Ritorna true se il nodo va tolto (visible falso, o visible su testo vuoto).
     * Con $id e profondità 1 registra il parametro del nc/section-ref del documento in $st['bound'].
     *
     * @param array<mixed> $node
     * @param array<string,mixed> $bind
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $st
     */
    private function applyParamBind(array &$node, string $key, array $bind, string $path, array $ctx, array &$st, ?string $id): bool
    {
        $at = $path . '/bind/' . $key;
        $prefix = explode('.', $key)[0];
        if ($key !== 'visible' && !in_array($prefix, ['content', 'settings', 'style'], true)) {
            $this->err($st, $ctx['override'], $at, __('bind key not supported', 'nucleo'), $key);
            return false;
        }
        $field = $bind['field'] ?? null;
        if (!is_string($field) || $field === '') {
            $this->err($st, $ctx['override'], $at, __('bind field missing', 'nucleo'));
            return false;
        }

        $parts = explode('.', $field, 2);
        $name = $parts[0];
        $sub = $parts[1] ?? null;
        if (!array_key_exists($name, $ctx['scope'])) {
            $this->err($st, $ctx['override'], $at, sprintf(/* translators: %s: parameter name. */ __('undeclared parameter: %s', 'nucleo'), (string) $name), $name, array_keys($ctx['scope']));
            return false;
        }
        // La voce di un repeat non è un parametro del nc/section-ref: non entra in $st['bound'].
        if ($id !== null && $ctx['depth'] === 1 && !(($ctx['repeated'] ?? false) && $name === 'item')) {
            $st['bound'][$id][$key] = $name;
        }

        $value = $ctx['scope'][$name];
        if ($sub !== null) {
            $value = is_array($value) ? ($value[$sub] ?? null) : null;
        } elseif (is_array($value)) {
            // Immagine senza sotto-campo: il bind prende la src.
            $value = $value['src'] ?? null;
        }

        if ($key === 'visible') {
            if ($value === false) {
                return true;
            }
            // Testo, html, link e immagine: il nodo si toglie se il valore è vuoto o assente.
            $type = $ctx['types'][$name] ?? null;

            return in_array($type, self::EMPTY_HIDES, true)
                && ($value === null || (is_string($value) && trim($value) === ''));
        }
        if ($value === null) {
            // Senza valore né fallback resta il valore scritto nel nodo: fa da segnaposto.
            $fallback = $bind['fallback'] ?? null;
            if (!is_string($fallback)) {
                return false;
            }
            $value = $fallback;
        }
        self::setAt($node, $key, $value);

        return false;
    }

    /**
     * Sostituisce ogni "$param.<nome>" nei valori di style (anche per breakpoint) con il valore di un parametro color,
     * anche dentro una stringa come "1px solid $param.colore". Il valore è un token $color.* (ParamTypes::matches);
     * il risultato passa poi dalla validazione dello stile come ogni altro valore.
     * Se un riferimento non si risolve (né valore né default) la proprietà resta fuori.
     *
     * @param array<mixed> $style
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $st
     * @return array<mixed>
     */
    private function styleParams(array $style, string $path, array $ctx, array &$st, ?string $id): array
    {
        foreach ($style as $prop => $value) {
            $responsive = is_array($value);
            $entries = $responsive ? $value : ['' => $value];
            foreach ($entries as $bp => $one) {
                if (!is_string($one) || !str_contains($one, self::PARAM_PREFIX)) {
                    continue;
                }
                $at = $path . '/style/' . $prop . ($responsive ? '/' . $bp : '');
                $failed = false;
                $resolved = preg_replace_callback(
                    self::PARAM_REF,
                    function (array $m) use ($at, $ctx, $id, $prop, &$st, &$failed): string {
                        $value = $this->styleParam($m[1], $at, $ctx, $st);
                        if ($value === null) {
                            $failed = true;

                            return $m[0];
                        }
                        if ($id !== null && $ctx['depth'] === 1) {
                            // Con più riferimenti nello stesso valore resta il primo.
                            $st['bound'][$id]['style.' . $prop] ??= $m[1];
                        }

                        return $value;
                    },
                    $one,
                );
                if ($failed || $resolved === null) {
                    unset($entries[$bp]);
                    continue;
                }
                $entries[$bp] = $resolved;
            }
            if ($entries === []) {
                unset($style[$prop]);
            } else {
                $style[$prop] = $responsive ? $entries : $entries[''];
            }
        }

        return $style;
    }

    /**
     * Valore di "$param.<nome>" nello stile; null con un errore se il riferimento non è valido, null senza errore se il parametro non ha valore.
     *
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $st
     */
    private function styleParam(string $name, string $at, array $ctx, array &$st): ?string
    {
        if ($ctx['scope'] === null) {
            $this->err($st, $ctx['override'], $at, __('parameter reference outside a reusable section', 'nucleo'), self::PARAM_PREFIX . $name);
            return null;
        }
        if (!array_key_exists($name, $ctx['scope'])) {
            $this->err($st, $ctx['override'], $at, sprintf(/* translators: %s: parameter name. */ __('undeclared parameter: %s', 'nucleo'), $name), $name, array_keys($ctx['scope']));
            return null;
        }
        if (($ctx['types'][$name] ?? null) !== 'color') {
            $this->err($st, $ctx['override'], $at, __('only color parameters can be used in style', 'nucleo'), $name, [], __('declare the parameter with type color, or use bind with source param', 'nucleo'));
            return null;
        }
        $value = $ctx['scope'][$name];

        return is_string($value) ? $value : null;
    }

    /**
     * Dichiarazioni di doc.params. Quelle non valide diventano errori e restano fuori.
     *
     * @param array<string,mixed> $st
     * @return array<string,array{type:string,required:bool,hasDefault:bool,default:mixed}>
     */
    private function declared(mixed $params, ?string $override, array &$st): array
    {
        $out = [];
        if (!is_array($params)) {
            return $out;
        }
        foreach ($params as $name => $decl) {
            $own = '/params/' . $name;
            if (!is_string($name) || !ParamTypes::validName($name)) {
                $this->err($st, $override, $own, __('invalid parameter name', 'nucleo'), $name, [], __('use lowercase letters, digits and _: ^[a-z][a-z0-9_]{0,31}$', 'nucleo'));
                continue;
            }
            $type = is_array($decl) ? ($decl['type'] ?? null) : null;
            if (!ParamTypes::known($type)) {
                $this->err($st, $override, $own, __('unknown parameter type', 'nucleo'), $type, ParamTypes::TYPES);
                continue;
            }
            $default = is_array($decl) ? ($decl['default'] ?? null) : null;
            $hasDefault = $default !== null;
            if ($hasDefault && !ParamTypes::matches($type, $default)) {
                $this->err($st, $override, $own, sprintf(/* translators: %s: parameter type. */ __('wrong default type for %s', 'nucleo'), $type), $default, [], sprintf(/* translators: %s: parameter type. */ __('the default must be of type %s', 'nucleo'), $type));
                $hasDefault = false;
            }
            $out[$name] = [
                'type' => $type,
                'required' => is_array($decl) && ($decl['required'] ?? false) === true,
                'hasDefault' => $hasDefault,
                'default' => $hasDefault ? $default : null,
            ];
        }

        return $out;
    }

    /**
     * Valori di una sezione: quelli passati (null = non passato), poi i default. Ogni dichiarazione è presente, null se assente.
     *
     * @param array<string,array{type:string,required:bool,hasDefault:bool,default:mixed}> $decls
     * @param array<mixed> $given
     * @param array<string,mixed> $st
     * @return array<string,mixed>
     */
    private function resolveValues(array $decls, array $given, string $paramsPath, ?string $override, bool $checkRequired, array &$st): array
    {
        $values = [];
        foreach ($given as $name => $value) {
            $own = $paramsPath . '/' . $name;
            if (!is_string($name) || !array_key_exists($name, $decls)) {
                $this->err($st, $override, $own, sprintf(/* translators: %s: parameter name. */ __('undeclared parameter: %s', 'nucleo'), (string) $name), $name, array_keys($decls));
                continue;
            }
            if ($value === null) {
                continue;
            }
            if (!ParamTypes::matches($decls[$name]['type'], $value)) {
                $this->err($st, $override, $own, sprintf(/* translators: %s: parameter type. */ __('wrong value type: expected %s', 'nucleo'), $decls[$name]['type']), $value, [], sprintf(/* translators: %s: parameter type. */ __('use a value of type %s', 'nucleo'), $decls[$name]['type']));
                continue;
            }
            $values[$name] = $value;
        }
        foreach ($decls as $name => $decl) {
            if (array_key_exists($name, $values)) {
                continue;
            }
            if ($decl['hasDefault']) {
                $values[$name] = $decl['default'];
                continue;
            }
            if ($checkRequired && $decl['required']) {
                $this->err($st, $override, $paramsPath . '/' . $name, __('required parameter without value or default', 'nucleo'));
            }
            $values[$name] = null;
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $st
     * @param list<mixed> $allowed
     */
    private function err(array &$st, ?string $override, string $own, string $message, mixed $got = null, array $allowed = [], ?string $fix = null): void
    {
        $st['errors'][] = new ValidationError($override ?? $own, $message, $got, $allowed, $fix);
    }

    /** @param array<string,mixed> $node */
    private function idOf(array $node, string $path): string
    {
        $id = $node['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : $path;
    }

    /**
     * Id del nodo nel documento espanso: null fuori da una sezione espansa.
     *
     * @param array<string,mixed> $ctx
     */
    private function expandedId(array $ctx, bool $isRoot, string $origId): ?string
    {
        if ($ctx['refId'] === null) {
            return null;
        }
        if ($isRoot) {
            return $ctx['refId'];
        }

        return substr($ctx['refId'], 0, 4) . substr(sha1($ctx['refId'] . '/' . $origId), 0, 8);
    }

    /**
     * Scrive il valore sul percorso puntato da key (es. "settings.params.K"), creando i livelli mancanti.
     * @param array<mixed> $node
     */
    private static function setAt(array &$node, string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $last = array_pop($parts);
        $ref = &$node;
        foreach ($parts as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref[$last] = $value;
    }
}
