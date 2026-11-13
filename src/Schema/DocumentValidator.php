<?php

declare(strict_types=1);

namespace Nucleo\Schema;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Core\IconRenderer;
use Nucleo\Blocks\Manifest;
use Nucleo\Blocks\NodeValidator;
use Nucleo\Blocks\Registry;
use Nucleo\Compiler\DocumentService;
use Nucleo\Compiler\LinkRefs;
use Nucleo\Css\Motion;
use Nucleo\Css\CustomRules;
use Nucleo\Css\PropertyMap;
use Nucleo\Site\SiteIcons;
use Nucleo\Tokens\TokenResolver;
use RuntimeException;

/** Valida un documento: prima lo schema JSON, poi le regole che dipendono dai manifest dei blocchi. */
final class DocumentValidator
{
    private const SCHEMA_FILE = __DIR__ . '/../../schema/document.schema.json';

    /** Tipi JSON Schema veri. Gli altri tipi dei manifest (es. "inline-rich-text") si validano come stringa. */
    private const JSON_TYPES = ['string', 'integer', 'number', 'boolean', 'array', 'object'];

    private const ROLE_CONDITION = '/^role:[a-z0-9_-]+$/D';

    /** Regola dell'id di un nodo: stessa di document.schema.json $defs.nodeId. */
    private const NODE_ID = '/^[a-z0-9]{1,12}$/D';

    /** Condizioni sul valore di un campo: filled e empty, con source e field. */
    private const FIELD_CONDITIONS = ['filled', 'empty'];

    /** Sorgenti ammesse in visibility.when: stesso elenco di document.schema.json. */
    private const FIELD_SOURCES = ['post', 'nucleo', 'item', 'acf', 'metabox', 'jetengine', 'author', 'term', 'site'];

    /** Codice degli errori di stile su un blocco che non ha il gruppo: DocumentService li riassume tutti insieme. */
    public const STYLE_GROUP_CODE = 'style_group_unsupported';

    /** Contenitore proposto per avvolgere un nodo che non ha il gruppo di stile. */
    private const WRAPPER = 'nc/container';

    /** Figli citati al massimo nel suggerimento di una chiave di stile da spostare. */
    private const MOVE_IDS_MAX = 4;




    /** @var array<string,mixed>|null */
    private ?array $schema = null;

    private readonly JsonSchemaValidator $validator;

    /**
     * @param ?TokenResolver $tokens token del sito: un riferimento $gruppo.chiave sconosciuto nello stile è un errore.
     *                               Senza token il controllo si salta (test dei soli manifest).
     */
    public function __construct(private readonly Registry $r, ?JsonSchemaValidator $v = null, private readonly ?TokenResolver $tokens = null)
    {
        $this->validator = $v ?? new JsonSchemaValidator();
    }

    /**
     * @param array<string,mixed> $doc
     * @return list<ValidationError>
     */
    public function validate(array $doc): array
    {
        $errors = $this->validator->validate($doc, $this->documentSchema());

        $ids = [];
        $walked = [];
        if (is_array($doc['root'] ?? null)) {
            $this->walk($doc['root'], '/root', null, $ids, $walked);
        }

        // Un id che la regola rifiuta lo segnala walk() con la regola e la proposta: l'errore dello schema sullo stesso campo si toglie.
        $idPaths = [];
        foreach ($walked as $e) {
            if (str_ends_with($e->path, '/id')) {
                $idPaths[] = $e->path;
            }
        }
        $errors = array_values(array_filter($errors, static fn (ValidationError $e): bool => !(str_ends_with($e->path, '/id') && in_array($e->path, $idPaths, true))));

        return $this->unique([...$errors, ...$walked]);
    }

    /** @return array<string,mixed> */
    private function documentSchema(): array
    {
        if ($this->schema === null) {
            $raw = file_get_contents(self::SCHEMA_FILE);
            $decoded = $raw === false ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new RuntimeException(sprintf(/* translators: %s: schema file path */ __('document schema is not readable: %s', 'nucleo'), self::SCHEMA_FILE));
            }
            $this->schema = $decoded;
        }

        return $this->schema;
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,true> $ids
     * @param list<ValidationError> $errors
     */
    private function walk(array $node, string $path, ?string $parent, array &$ids, array &$errors): void
    {
        $type = $node['type'] ?? null;
        $manifest = is_string($type) ? $this->r->get($type) : null;

        if ($manifest === null) {
            $errors[] = new ValidationError(
                "$path/type",
                sprintf(/* translators: %s: block type, or "missing" when absent */ __('block type not registered: %s', 'nucleo'), is_string($type) ? $type : __('missing', 'nucleo')),
                is_string($type) ? $type : null,
                array_keys($this->r->all()),
                __('use one of the registered block types', 'nucleo'),
            );
        }

        $id = $node['id'] ?? null;
        if (is_string($id)) {
            if (preg_match(self::NODE_ID, $id) !== 1) {
                $fixed = substr((string) preg_replace('/[^a-z0-9]/', '', strtolower($id)), 0, 12);
                // translators: %s: suggested node id.
                $hint = $fixed !== '' ? sprintf(__('use "%s"', 'nucleo'), $fixed) : __('use 1 to 12 lowercase letters or digits', 'nucleo');
                // translators: %s: hint on how to fix the id.
                $errors[] = new ValidationError("$path/id", sprintf(__('id must match ^[a-z0-9]{1,12}$ (lowercase letters and digits, max 12): %s', 'nucleo'), $hint), $id, [], $fixed !== '' ? $fixed : $hint);
            }
            if (isset($ids[$id])) {
                $errors[] = new ValidationError("$path/id", sprintf(/* translators: %s: duplicated id */ __('duplicate id: %s', 'nucleo'), $id), $id, [], __('every id in the document must be unique', 'nucleo'));
            }
            $ids[$id] = true;
        }

        if ($manifest !== null) {
            $this->checkParent($manifest, $parent, $path, $errors);
            $this->checkProps($node, 'content', $manifest->content, $path, $errors);
            $this->checkProps($node, 'settings', $manifest->settings, $path, $errors);
            $this->checkStyle($node, $manifest, $parent, $path, $errors);
            $this->checkBind($node, $manifest, $path, $errors);
            $this->checkNode($node, $manifest, $path, $errors);
            if ($manifest->name === 'nc/icon') {
                $this->checkIcon($node, $path, $errors);
            }
        }

        $this->checkUrls($node, $path, $errors);
        $this->checkVisibility($node, $path, $errors);

        $children = $node['children'] ?? [];
        if (!is_array($children)) {
            return;
        }

        if ($manifest !== null && $children !== []) {
            $this->checkChildTypes($manifest, $children, $path, $errors);
        }

        foreach ($children as $i => $child) {
            if (is_array($child)) {
                $this->walk($child, "$path/children/$i", is_string($type) ? $type : null, $ids, $errors);
            }
        }
    }

    /**
     * Chiavi di bind: content.<nome> o settings.<nome> dichiarati dal blocco. Le altre il compilatore le scarta in silenzio.
     * @param array<string,mixed> $node
     * @param list<ValidationError> $errors
     */
    private function checkBind(array $node, Manifest $manifest, string $path, array &$errors): void
    {
        $bind = $node['bind'] ?? [];
        if (!is_array($bind)) {
            return;
        }
        $content = array_keys($manifest->content);
        $allowed = array_merge(
            array_map(static fn (string $k): string => "content.$k", array_map('strval', $content)),
            array_map(static fn (string $k): string => "settings.$k", array_map('strval', array_keys($manifest->settings))),
        );
        foreach (array_keys($bind) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $errors[] = new ValidationError(
                    "$path/bind/$key",
                    sprintf(/* translators: %s: bind key */ __('bind key not declared in the block: %s', 'nucleo'), (string) $key),
                    (string) $key,
                    $allowed,
                    __('use content.<name> or settings.<name> with a property of the block', 'nucleo'),
                );
            }
        }
    }

    /**
     * Nome di nc/icon: un'icona del set interno oppure un'icona del sito (opzione nucleo_icons).
     * @param array<string,mixed> $node
     * @param list<ValidationError> $errors
     */
    private function checkIcon(array $node, string $path, array &$errors): void
    {
        $settings = $node['settings'] ?? null;
        $name = is_array($settings) ? ($settings['name'] ?? null) : null;
        if (!is_string($name)) {
            return; // Il nome mancante o di tipo sbagliato lo segnala lo schema.
        }

        $allowed = [...IconRenderer::names(), ...array_map('strval', array_keys(SiteIcons::all()))];
        if (in_array($name, $allowed, true)) {
            return;
        }

        $errors[] = new ValidationError(
            "$path/settings/name",
            sprintf(/* translators: %s: icon name */ __('unknown icon: %s', 'nucleo'), $name),
            $name,
            $allowed,
            __('use a built-in icon or add a site icon with nucleo-icons-set', 'nucleo'),
        );
    }

    /**
     * Controlli propri del blocco, se il renderer implementa NodeValidator.
     * @param array<string,mixed> $node
     * @param list<ValidationError> $errors
     */
    private function checkNode(array $node, Manifest $manifest, string $path, array &$errors): void
    {
        try {
            $renderer = $this->r->renderer($manifest->name);
        } catch (\Throwable) {
            return; // Renderer non caricabile: il compilatore lo segnala.
        }
        if ($renderer instanceof NodeValidator) {
            array_push($errors, ...$renderer->validateNode($node, $path));
        }
    }

    /**
     * @param array<mixed> $children
     * @param list<ValidationError> $errors
     */
    private function checkChildTypes(Manifest $manifest, array $children, string $path, array &$errors): void
    {
        if ($manifest->children === false) {
            $errors[] = new ValidationError(
                "$path/children",
                sprintf(/* translators: %s: block name */ __('block %s does not accept children', 'nucleo'), $manifest->name),
                null,
                [],
                __('remove the children or use a container block', 'nucleo'),
            );
            return;
        }

        if (!is_array($manifest->children)) {
            return;
        }

        foreach ($children as $i => $child) {
            $childType = is_array($child) ? ($child['type'] ?? null) : null;
            if (!is_string($childType) || !in_array($childType, $manifest->children, true)) {
                $errors[] = new ValidationError(
                    "$path/children/$i/type",
                    sprintf(/* translators: %s: block name */ __('child type not allowed by %s', 'nucleo'), $manifest->name),
                    is_string($childType) ? $childType : null,
                    $manifest->children,
                    __('use one of the allowed child types', 'nucleo'),
                );
            }
        }
    }

    /** @param list<ValidationError> $errors */
    private function checkParent(Manifest $manifest, ?string $parent, string $path, array &$errors): void
    {
        if ($manifest->parents === null || in_array($parent, $manifest->parents, true)) {
            return;
        }

        $errors[] = new ValidationError(
            "$path/type",
            sprintf(/* translators: 1: block name, 2: parent block type */ __('%1$s cannot be placed under %2$s', 'nucleo'), $manifest->name, $parent ?? __('the root', 'nucleo')),
            $parent,
            $manifest->parents,
            __('move it under one of the allowed parent blocks', 'nucleo'),
        );
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,array<string,mixed>> $decl
     * @param list<ValidationError> $errors
     */
    private function checkProps(array $node, string $key, array $decl, string $path, array &$errors): void
    {
        $values = $node[$key] ?? [];
        if (!is_array($values)) {
            return; // Lo schema JSON segnala già il tipo sbagliato.
        }

        $this->checkValues($values, "$path/$key", $decl, $key, $errors);
    }

    /**
     * Chiavi dichiarate, valori e campi required di content, settings o di un elemento di un array con "item".
     * @param array<mixed> $values
     * @param array<string,mixed> $decl
     * @param list<ValidationError> $errors
     */
    private function checkValues(array $values, string $base, array $decl, string $label, array &$errors): void
    {
        foreach ($values as $name => $value) {
            $name = (string) $name;
            if (!isset($decl[$name]) || !is_array($decl[$name])) {
                $errors[] = new ValidationError(
                    "$base/$name",
                    sprintf(/* translators: 1: "content", "settings" or "item", 2: property name */ __('%1$s not declared in the block: %2$s', 'nucleo'), $label, $name),
                    is_array($value) ? null : $value,
                    array_keys($decl),
                    __('remove the key or use one of the allowed ones', 'nucleo'),
                );
                continue;
            }

            foreach ($this->validator->validate($value, $this->propertySchema($decl[$name])) as $e) {
                $errors[] = new ValidationError("$base/$name" . $e->path, $e->message, $e->got, $e->allowed, $e->fix);
            }

            // Array con "item" nel manifest: ogni elemento è un oggetto con le chiavi dichiarate in "item".
            $item = $decl[$name]['item'] ?? null;
            if (is_array($item) && is_array($value)) {
                foreach ($value as $i => $element) {
                    if (!is_array($element)) {
                        $errors[] = new ValidationError("$base/$name/$i", __('each item must be an object', 'nucleo'), $element, [], __('use an object with the item keys', 'nucleo'));
                        continue;
                    }
                    $this->checkValues($element, "$base/$name/$i", $item, 'item', $errors);
                }
            }
        }

        foreach ($decl as $name => $prop) {
            if (is_array($prop) && ($prop['required'] ?? false) === true && !array_key_exists((string) $name, $values)) {
                $errors[] = new ValidationError(
                    "$base/$name",
                    sprintf(/* translators: %s: property name */ __('required field missing: %s', 'nucleo'), $name),
                    null,
                    [],
                    __('add the required value', 'nucleo'),
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $node
     * @param list<ValidationError> $errors
     */
    private function checkStyle(array $node, Manifest $manifest, ?string $parent, string $path, array &$errors): void
    {
        $supports = $manifest->supports;
        $style = $node['style'] ?? [];
        if (!is_array($style)) {
            return;
        }

        // Chiavi riservate: style.parts e style.custom si validano qui, non come gruppi di stile.
        if (array_key_exists('parts', $style)) {
            $this->checkParts($style['parts'], $manifest, $path, $errors);
        }
        if (array_key_exists('custom', $style)) {
            $this->checkCustom($style['custom'], $path, $errors);
        }

        $groups = is_array($supports['style'] ?? null) ? array_values($supports['style']) : [];
        foreach ($style as $key => $value) {
            $key = (string) $key;
            if ($key === 'parts' || $key === 'custom') {
                continue;
            }
            $at = "$path/style/$key";
            $group = PropertyMap::group($key);

            if ($group === null) {
                $errors[] = new ValidationError(
                    $at,
                    sprintf(/* translators: %s: style property name */ __('unknown style property: %s', 'nucleo'), $key),
                    $key,
                    $groups,
                    __('use a property from one of the style groups supported by the block', 'nucleo'),
                );
                continue;
            }
            if (!in_array($group, $groups, true)) {
                $errors[] = new ValidationError(
                    $at,
                    sprintf(/* translators: %s: style group name */ __('style group "%s" not supported by the block', 'nucleo'), $group),
                    $key,
                    $groups,
                    $this->whereStyleGoes($node, $manifest, $parent, $path, $key, $value, $group),
                    self::STYLE_GROUP_CODE,
                );
                continue;
            }

            $this->checkStyleValue($key, $group, $value, $at, $errors);
        }
    }

    /**
     * Valore di una chiave di stile, semplice oppure per breakpoint (base, md, lg, xl). Lo usano i nodi e le parti.
     *
     * @param list<ValidationError> $errors
     */
    private function checkStyleValue(string $key, string $group, mixed $value, string $at, array &$errors): void
    {
        $values = is_array($value) ? $value : [$value];
        foreach ($values as $bp => $v) {
            if ($group === 'motion' && !Motion::accepts($key, $v)) {
                $errors[] = new ValidationError(
                    is_array($value) ? "$at/$bp" : $at,
                    sprintf(/* translators: %s: style property name */ __('motion value not allowed for %s', 'nucleo'), $key),
                    $v,
                    Motion::allowed($key),
                    __('use one of the allowed motion values', 'nucleo'),
                );
                continue;
            }
            if (!PropertyMap::accepts($key, $v)) {
                $errors[] = new ValidationError(
                    is_array($value) ? "$at/$bp" : $at,
                    sprintf(/* translators: %s: style property name */ __('value not allowed for %s', 'nucleo'), $key),
                    $v,
                    PropertyMap::allowed($key),
                    __('use one of the allowed values', 'nucleo'),
                );
                continue;
            }
            if (is_string($v) && !Sanitizer::cssValue($v)) {
                $errors[] = new ValidationError(
                    is_array($value) ? "$at/$bp" : $at,
                    sprintf(/* translators: %s: style property name */ __('CSS value not allowed for %s', 'nucleo'), $key),
                    $v,
                    [],
                    __('remove ; { } < > \\ /* */ url( image-set( image( cross-fade( element( src( expression( @import and control characters; close every parenthesis and quote', 'nucleo'),
                );
                continue;
            }
            $unknown = is_string($v) ? $this->unknownToken($v) : null;
            if ($unknown !== null) {
                $errors[] = new ValidationError(
                    is_array($value) ? "$at/$bp" : $at,
                    sprintf(/* translators: %s: token reference, e.g. $space.20 */ __('unknown token: %s', 'nucleo'), $unknown),
                    $v,
                    [],
                    __('use a token that exists (nucleo/tokens-get) or add it with nucleo/tokens-set', 'nucleo'),
                );
            }
        }
    }

    /**
     * style.parts: oggetto nome parte → chiavi di stile. Le parti sono quelle del manifest; i gruppi sono PropertyMap::PART_GROUPS.
     *
     * @param list<ValidationError> $errors
     */
    private function checkParts(mixed $parts, Manifest $manifest, string $path, array &$errors): void
    {
        $base = "$path/style/parts";
        if (!is_array($parts)) {
            $errors[] = new ValidationError($base, __('style.parts must be an object of part name to style', 'nucleo'), $parts, [], __('use {part: {styleKey: value}}', 'nucleo'));
            return;
        }

        $declared = array_keys($manifest->parts);
        foreach ($parts as $name => $partStyle) {
            $name = (string) $name;
            $partAt = "$base/$name";
            if (!in_array($name, $declared, true)) {
                $errors[] = new ValidationError(
                    $partAt,
                    sprintf(/* translators: %s: part name */ __('part not declared in the block: %s', 'nucleo'), $name),
                    $name,
                    $declared,
                    __('use one of the parts declared by the block', 'nucleo'),
                );
                continue;
            }
            if (!is_array($partStyle)) {
                $errors[] = new ValidationError($partAt, sprintf(/* translators: %s: part name */ __('style of the part must be an object: %s', 'nucleo'), $name), $partStyle, [], __('use {styleKey: value}', 'nucleo'));
                continue;
            }

            foreach ($partStyle as $key => $value) {
                $key = (string) $key;
                $at = "$partAt/$key";
                $group = PropertyMap::group($key);
                if ($group === null) {
                    $errors[] = new ValidationError(
                        $at,
                        sprintf(/* translators: %s: style property name */ __('unknown style property: %s', 'nucleo'), $key),
                        $key,
                        PropertyMap::PART_GROUPS,
                        __('use a property from one of the style groups allowed in a part', 'nucleo'),
                    );
                    continue;
                }
                if (!in_array($group, PropertyMap::PART_GROUPS, true)) {
                    $errors[] = new ValidationError(
                        $at,
                        sprintf(/* translators: %s: style group name */ __('style group "%s" not allowed in a part', 'nucleo'), $group),
                        $key,
                        PropertyMap::PART_GROUPS,
                        __('use a property from one of the style groups allowed in a part', 'nucleo'),
                    );
                    continue;
                }
                $this->checkStyleValue($key, $group, $value, $at, $errors);
            }
        }
    }

    /**
     * style.custom: lista di regole strutturate (CustomRules); un errore per struttura, proprietà o valore.
     *
     * @param list<ValidationError> $errors
     */
    private function checkCustom(mixed $custom, string $path, array &$errors): void
    {
        $base = "$path/style/custom";
        // Struttura, selettore e nomi delle proprietà: liste chiuse di CustomRules. Qui si controllano i valori.
        $structure = CustomRules::errors($custom, $base);
        if ($structure !== [] || !is_array($custom)) {
            array_push($errors, ...$structure);
            return;
        }

        foreach ($custom as $i => $rule) {
            foreach ($rule['css'] as $property => $value) {
                $property = (string) $property;
                $at = "$base/$i/css/$property";
                if (!is_string($value) && !is_int($value) && !is_float($value)) {
                    $errors[] = new ValidationError(
                        $at,
                        sprintf(/* translators: %s: custom property name */ __('custom value must be a string or a number: %s', 'nucleo'), $property),
                        is_array($value) ? null : $value,
                        [],
                        __('use a string or a number', 'nucleo'),
                    );
                    continue;
                }
                $text = (string) $value;
                if (!Sanitizer::cssValue($text)) {
                    $errors[] = new ValidationError(
                        $at,
                        sprintf(/* translators: %s: custom property name */ __('custom CSS value not allowed for %s', 'nucleo'), $property),
                        $value,
                        [],
                        __('remove ; { } < > \\ /* */ url( image-set( image( cross-fade( element( src( expression( @import and control characters; close every parenthesis and quote', 'nucleo'),
                    );
                    continue;
                }
                $unknown = is_string($value) ? $this->unknownToken($value) : null;
                if ($unknown !== null) {
                    $errors[] = new ValidationError(
                        $at,
                        sprintf(/* translators: %s: token reference, e.g. $space.20 */ __('unknown token: %s', 'nucleo'), $unknown),
                        $value,
                        [],
                        __('use a token that exists (nucleo/tokens-get) or add it with nucleo/tokens-set', 'nucleo'),
                    );
                }
            }
        }
    }

    /** Primo riferimento $gruppo.chiave del valore che non esiste nei token del sito, o null. */
    private function unknownToken(string $value): ?string
    {
        if ($this->tokens === null || !str_contains($value, '$')) {
            return null;
        }
        preg_match_all('/\$[a-z]+\.[a-z0-9-]+/', $value, $matches);
        foreach ($matches[0] as $token) {
            if ($this->tokens->resolve($token) === null) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Dove va una chiave di stile che il blocco rifiuta: sui figli che hanno il gruppo, oppure su un contenitore
     * che avvolge il nodo (doc-patch wrap), oppure su uno dei blocchi che hanno il gruppo.
     * @param array<string,mixed> $node
     */
    private function whereStyleGoes(array $node, Manifest $manifest, ?string $parent, string $path, string $key, mixed $value, string $group): string
    {
        $ids = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            $m = is_array($child) && is_string($child['type'] ?? null) ? $this->r->get($child['type']) : null;
            if ($m !== null && in_array($group, (array) ($m->supports['style'] ?? []), true) && is_scalar($child['id'] ?? null)) {
                $ids[] = sprintf('%s (%s)', (string) $child['id'], $m->name);
            }
        }
        if ($ids !== []) {
            /* translators: 1: style key, 2: style group, 3: child node ids with their block types. */
            return sprintf(__('move %1$s to the children that accept the %2$s group: %3$s', 'nucleo'), $key, $group, implode(', ', array_slice($ids, 0, self::MOVE_IDS_MAX)));
        }

        $accepting = [];
        foreach ($this->r->all() as $name => $m) {
            if (in_array($group, (array) ($m->supports['style'] ?? []), true)) {
                $accepting[] = (string) $name;
            }
        }
        if ($this->canWrap($manifest, $parent, $path, $accepting) && is_scalar($node['id'] ?? null)) {
            $patch = wp_json_encode(['op' => 'wrap', 'ids' => [(string) $node['id']], 'with' => ['type' => self::WRAPPER, 'style' => [$key => $value]]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            /* translators: 1: style key, 2: wrapper block type, 3: doc-patch operation as JSON. */
            return sprintf(__('wrap the node in %2$s and put %1$s on the wrapper: doc-patch %3$s, or do the same in the file', 'nucleo'), $key, self::WRAPPER, (string) $patch);
        }

        /* translators: 1: style key, 2: style group, 3: block types. */
        return sprintf(__('put %1$s on a block that accepts the %2$s group: %3$s', 'nucleo'), $key, $group, implode(', ', $accepting));
    }

    /**
     * Il nodo si può avvolgere in nc/container: non è il root, può stare dentro un contenitore qualsiasi
     * (nessun parents nel manifest) e il genitore accetta nc/container; il contenitore ha il gruppo.
     * @param list<string> $accepting blocchi che hanno il gruppo
     */
    private function canWrap(Manifest $manifest, ?string $parent, string $path, array $accepting): bool
    {
        if ($path === '/root' || $manifest->parents !== null || !in_array(self::WRAPPER, $accepting, true)) {
            return false;
        }
        $parentManifest = $parent !== null ? $this->r->get($parent) : null;
        if ($parentManifest === null) {
            return false;
        }

        return $parentManifest->children === true || (is_array($parentManifest->children) && in_array(self::WRAPPER, $parentManifest->children, true));
    }

    /**
     * @param array<string,mixed> $node
     * @param list<ValidationError> $errors
     */
    private function checkUrls(array $node, string $path, array &$errors): void
    {
        $checks = [['settings', 'href'], ['content', 'src']];
        foreach ($checks as [$section, $key]) {
            $bag = $node[$section] ?? null;
            $value = is_array($bag) ? ($bag[$key] ?? null) : null;
            // settings.href accetta anche un riferimento interno ("page:chi-siamo"): diventa URL al salvataggio.
            if (is_string($value) && Sanitizer::url($value) === null && !($key === 'href' && LinkRefs::isRef($value))) {
                $errors[] = new ValidationError(
                    "$path/$section/$key",
                    sprintf(/* translators: 1: section name, 2: key name */ __('URL not allowed in %1$s.%2$s', 'nucleo'), $section, $key),
                    $value,
                    [],
                    $key === 'href' ? __('use an http(s), mailto:, tel: or relative URL, or a reference: home, page:<slug>, post:<slug>, <post-type>:<slug>, term:<taxonomy>/<slug>', 'nucleo') : __('use an http(s), mailto:, tel: or relative URL', 'nucleo'),
                );
            }
        }
    }

    /**
     * Condizioni di visibility.when: logged_in, logged_out, role:<slug>, oppure filled/empty con source e field.
     *
     * @param array<string,mixed> $node
     * @param list<ValidationError> $errors
     */
    private function checkVisibility(array $node, string $path, array &$errors): void
    {
        $visibility = $node['visibility'] ?? null;
        $when = is_array($visibility) ? ($visibility['when'] ?? null) : null;
        if (!is_array($when)) {
            return;
        }

        foreach ($when as $i => $condition) {
            $is = is_array($condition) ? ($condition['is'] ?? null) : null;
            if (is_string($is) && ($is === 'logged_in' || $is === 'logged_out' || preg_match(self::ROLE_CONDITION, $is) === 1)) {
                continue;
            }
            if (is_string($is) && in_array($is, self::FIELD_CONDITIONS, true)) {
                $this->checkFieldCondition(is_array($condition) ? $condition : [], "$path/visibility/when/$i", $errors);
                continue;
            }

            $errors[] = new ValidationError(
                "$path/visibility/when/$i/is",
                sprintf(/* translators: %s: condition found, or "missing" when absent */ __('visibility condition not allowed: %s', 'nucleo'), is_string($is) ? $is : __('missing', 'nucleo')),
                is_string($is) ? $is : null,
                ['logged_in', 'logged_out', 'role:<slug>', 'filled', 'empty'],
                __('use logged_in, logged_out, role: followed by a slug [a-z0-9_-]+, or filled and empty with a source and a field', 'nucleo'),
            );
        }
    }

    /**
     * Condizione filled o empty: source tra quelli di bind (elenco FIELD_SOURCES) e field non vuoto.
     *
     * @param array<string,mixed> $condition
     * @param list<ValidationError> $errors
     */
    private function checkFieldCondition(array $condition, string $path, array &$errors): void
    {
        $source = $condition['source'] ?? null;
        if (!is_string($source) || !in_array($source, self::FIELD_SOURCES, true)) {
            $errors[] = new ValidationError(
                "$path/source",
                sprintf(/* translators: %s: source found, or "missing" when absent */ __('visibility source not allowed: %s', 'nucleo'), is_string($source) ? $source : __('missing', 'nucleo')),
                is_string($source) ? $source : null,
                self::FIELD_SOURCES,
                __('use one of the allowed sources of a visibility condition', 'nucleo'),
            );
        }

        $field = $condition['field'] ?? null;
        if (!is_string($field) || trim($field) === '') {
            $errors[] = new ValidationError(
                "$path/field",
                __('visibility field missing', 'nucleo'),
                is_string($field) ? $field : null,
                [],
                __('add the field key, for example meta:pdf or the key of a repeater', 'nucleo'),
            );
        }
    }

    /**
     * @param list<ValidationError> $errors
     * @return list<ValidationError>
     */
    private function unique(array $errors): array
    {
        $seen = [];
        $out = [];
        foreach ($errors as $e) {
            $key = $e->path . "\0" . $e->message;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $e;
            }
        }

        return $out;
    }

    /**
     * Schema JSON di una proprietà: si tolgono le parole chiave del manifest che JSON Schema non conosce.
     *
     * @param array<string,mixed> $prop
     * @return array<string,mixed>
     */
    private function propertySchema(array $prop): array
    {
        $schema = array_diff_key($prop, array_flip(['type', 'required', 'translatable', 'default', 'item']));
        $type = $prop['type'] ?? null;
        if (is_string($type)) {
            $schema['type'] = in_array($type, self::JSON_TYPES, true) ? $type : 'string';
        }

        return $schema;
    }
}
