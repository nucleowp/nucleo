<?php

declare(strict_types=1);

namespace Nucleo\Runtime\Collection;

defined('ABSPATH') || exit;

/**
 * Configurazione di una nc/collection, validata e normalizzata (docs/15-dati-dinamici.md, livello 2).
 * Nasce dai settings al salvataggio e viaggia come array nei dati dello slot.
 *
 * Filtro: {key, label, type: taxonomy|field|date|search|param, ui, multiple: bool, taxonomy: string,
 * field: string, fieldType: string (tipo del campo del modello, "" se non c'è), param: string, presets: list<string>}.
 * Ordinamento: {key, label, orderby, order}.
 * Sorgente terms (tassonomia): taxonomy, parent ("" tutti, "0" primo livello, "<slug>" figli diretti), hideEmpty.
 * cardSize: "auto" o "small" (classe nc-collection--small).
 */
final class CollectionConfig
{
    private const MAX_FILTERS = 12;
    private const MAX_SORTS = 8;
    private const KEY_RE = '/\A[a-z][a-z0-9_]{0,23}\z/';
    private const NAME_RE = '/\A[a-z][a-z0-9_]{0,63}\z/';
    private const TAXONOMY_RE = '/\A[a-z0-9_-]{1,32}\z/';
    private const POST_TYPE_RE = '/\A[a-z0-9_-]{1,20}\z/';
    private const COMPONENT_RE = '/\A[A-Za-z0-9_.:\/-]{1,64}\z/';
    /** parent della sorgente terms: "0" o uno slug (anche con %xx, come li scrive sanitize_title). */
    public const PARENT_RE = '/\A(0|[\p{L}\p{N}_%-]{1,200})\z/u';
    /** parent non valido: nessuno slug lo usa, la pagina resta vuota invece di elencare tutti i termini. */
    private const BAD_PARENT = '!';
    private const PREFIX_RE = '/\A[a-z]{1,8}\z/';
    private const PICK_UI = ['pills', 'select', 'checkboxes', 'dropdown'];
    private const PARAM_UI = ['pills', 'select', 'checkboxes', 'dropdown', 'text', 'range'];
    private const PRESETS = ['upcoming', 'past', 'month'];
    private const TERM_ORDERBY = ['name', 'slug', 'count'];

    /**
     * @param list<array{key:string,label:string,type:string,ui:string,multiple:bool,taxonomy:string,field:string,fieldType:string,param:string,presets:list<string>}> $filters
     * @param list<array{key:string,label:string,orderby:string,order:string}> $sorts
     * @param list<string> $views
     */
    public function __construct(
        public readonly string $node,
        public readonly string $source,
        public readonly string $component,
        public readonly string $postType,
        public readonly int $perPage,
        public readonly string $orderby,
        public readonly string $order,
        public readonly string $prefix,
        public readonly array $filters,
        public readonly array $sorts,
        public readonly array $views,
        public readonly string $paging,
        public readonly bool $counts,
        public readonly string $mobileFilters,
        public readonly string $taxonomy = '',
        public readonly string $parent = '',
        public readonly bool $hideEmpty = true,
        public readonly string $cardSize = 'auto',
    ) {
    }

    /**
     * Dai settings del nodo. Valori fuori dominio tornano al default; filtri e ordinamenti malformati si scartano.
     * Con source terms non ci sono filtri, ordinamenti né conteggi: l'ordine è orderby (default name, asc).
     * @param array<string,mixed> $settings
     * @param array<string,string> $fieldTypes chiave del campo del modello → tipo (number, date, select, boolean, text…)
     */
    public static function fromSettings(string $node, array $settings, array $fieldTypes): self
    {
        $source = self::enum($settings['source'] ?? null, ['posts', 'component', 'terms'], 'posts');
        $terms = $source === 'terms';
        $component = $source === 'component' ? self::matching($settings['component'] ?? null, self::COMPONENT_RE) : '';
        $postType = $source === 'posts' ? self::matching($settings['postType'] ?? null, self::POST_TYPE_RE) : '';
        $taxonomy = $terms ? self::matching($settings['taxonomy'] ?? null, self::TAXONOMY_RE) : '';
        $rawParent = $settings['parent'] ?? '';
        $parent = !$terms || $rawParent === '' ? '' : self::matching($rawParent, self::PARENT_RE);
        if ($terms && $rawParent !== '' && $parent === '') {
            $parent = self::BAD_PARENT;
        }
        $orderby = self::validOrderby($settings['orderby'] ?? null, $fieldTypes, $source)
            ? (string) $settings['orderby']
            : ($terms ? 'name' : 'date');
        $prefix = self::matching($settings['prefix'] ?? null, self::PREFIX_RE);
        if ($prefix === '') {
            $prefix = 'f';
        }

        return new self(
            node: $node,
            source: $source,
            component: $component,
            postType: $postType,
            perPage: self::intIn($settings['perPage'] ?? null, 1, 48, 12),
            orderby: $orderby,
            order: self::enum($settings['order'] ?? null, ['asc', 'desc'], $terms ? 'asc' : 'desc'),
            prefix: $prefix,
            filters: $terms ? [] : self::parseFilters(self::items($settings['filters'] ?? null), $fieldTypes),
            sorts: $terms ? [] : self::parseSorts(self::items($settings['sorts'] ?? null), $fieldTypes, $source),
            views: self::parseViews(self::items($settings['views'] ?? null)),
            paging: self::enum($settings['paging'] ?? null, ['pages', 'more', 'none'], 'pages'),
            counts: !$terms && (is_bool($settings['counts'] ?? null) ? $settings['counts'] : true),
            mobileFilters: self::enum($settings['mobileFilters'] ?? null, ['drawer', 'inline'], 'drawer'),
            taxonomy: $taxonomy,
            parent: $parent,
            hideEmpty: is_bool($settings['hideEmpty'] ?? null) ? $settings['hideEmpty'] : true,
            cardSize: self::enum($settings['cardSize'] ?? null, ['auto', 'small'], 'auto'),
        );
    }

    /**
     * @param array<string,mixed> $data output di toArray(); null se malformato.
     * I campi della sorgente terms mancanti (dati salvati prima) prendono il default.
     */
    public static function fromArray(array $data): ?self
    {
        $data += ['taxonomy' => '', 'parent' => '', 'hideEmpty' => true, 'cardSize' => 'auto'];
        if (
            !self::hasTypes($data, [
            'node' => 'string', 'source' => 'string', 'component' => 'string', 'postType' => 'string',
            'perPage' => 'int', 'orderby' => 'string', 'order' => 'string', 'prefix' => 'string',
            'filters' => 'list', 'sorts' => 'list', 'views' => 'list', 'paging' => 'string',
            'counts' => 'bool', 'mobileFilters' => 'string',
            'taxonomy' => 'string', 'parent' => 'string', 'hideEmpty' => 'bool', 'cardSize' => 'string',
            ])
        ) {
            return null;
        }
        if ($data['node'] === '' || !in_array($data['source'], ['posts', 'component', 'terms'], true)) {
            return null;
        }
        if ($data['source'] === 'posts' && $data['postType'] === '') {
            return null;
        }
        if ($data['source'] === 'component' && $data['component'] === '') {
            return null;
        }
        if ($data['source'] === 'terms' && $data['taxonomy'] === '') {
            return null;
        }
        foreach ($data['filters'] as $filter) {
            if (
                !is_array($filter) || !self::hasTypes($filter, [
                'key' => 'string', 'label' => 'string', 'type' => 'string', 'ui' => 'string',
                'multiple' => 'bool', 'taxonomy' => 'string', 'field' => 'string', 'fieldType' => 'string',
                'param' => 'string', 'presets' => 'list',
                ])
            ) {
                return null;
            }
            foreach ($filter['presets'] as $preset) {
                if (!is_string($preset)) {
                    return null;
                }
            }
        }
        foreach ($data['sorts'] as $sort) {
            if (
                !is_array($sort) || !self::hasTypes($sort, [
                'key' => 'string', 'label' => 'string', 'orderby' => 'string', 'order' => 'string',
                ])
            ) {
                return null;
            }
        }
        foreach ($data['views'] as $view) {
            if (!is_string($view)) {
                return null;
            }
        }

        return new self(
            node: $data['node'],
            source: $data['source'],
            component: $data['component'],
            postType: $data['postType'],
            perPage: $data['perPage'],
            orderby: $data['orderby'],
            order: $data['order'],
            prefix: $data['prefix'],
            filters: $data['filters'],
            sorts: $data['sorts'],
            views: $data['views'],
            paging: $data['paging'],
            counts: $data['counts'],
            mobileFilters: $data['mobileFilters'],
            taxonomy: $data['taxonomy'],
            parent: $data['parent'],
            hideEmpty: $data['hideEmpty'],
            cardSize: $data['cardSize'],
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'node' => $this->node,
            'source' => $this->source,
            'component' => $this->component,
            'postType' => $this->postType,
            'perPage' => $this->perPage,
            'orderby' => $this->orderby,
            'order' => $this->order,
            'prefix' => $this->prefix,
            'filters' => $this->filters,
            'sorts' => $this->sorts,
            'views' => $this->views,
            'paging' => $this->paging,
            'counts' => $this->counts,
            'mobileFilters' => $this->mobileFilters,
            'taxonomy' => $this->taxonomy,
            'parent' => $this->parent,
            'hideEmpty' => $this->hideEmpty,
            'cardSize' => $this->cardSize,
        ];
    }

    /** Nome del parametro URL: "<prefix>_<name>" (name = chiave di filtro, "sort", "page", "<key>_min"…). */
    public function param(string $name): string
    {
        return $this->prefix . '_' . $name;
    }

    /** @return array{key:string,label:string,type:string,ui:string,multiple:bool,taxonomy:string,field:string,fieldType:string,param:string,presets:list<string>}|null */
    public function filter(string $key): ?array
    {
        foreach ($this->filters as $filter) {
            if ($filter['key'] === $key) {
                return $filter;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $raws
     * @param array<string,string> $fieldTypes
     * @return list<array{key:string,label:string,type:string,ui:string,multiple:bool,taxonomy:string,field:string,fieldType:string,param:string,presets:list<string>}>
     */
    private static function parseFilters(array $raws, array $fieldTypes): array
    {
        $filters = [];
        foreach ($raws as $raw) {
            if (count($filters) >= self::MAX_FILTERS) {
                break;
            }
            $filter = self::parseFilter($raw, $fieldTypes);
            if ($filter === null || in_array($filter['key'], array_column($filters, 'key'), true)) {
                continue;
            }
            $filters[] = $filter;
        }

        return $filters;
    }

    /**
     * @param array<string,string> $fieldTypes
     * @return array{key:string,label:string,type:string,ui:string,multiple:bool,taxonomy:string,field:string,fieldType:string,param:string,presets:list<string>}|null
     */
    private static function parseFilter(mixed $raw, array $fieldTypes): ?array
    {
        if (!is_array($raw) || !self::validKey($raw['key'] ?? null)) {
            return null;
        }
        $key = (string) $raw['key'];
        $type = is_string($raw['type'] ?? null) ? $raw['type'] : '';
        $ui = is_string($raw['ui'] ?? null) ? $raw['ui'] : '';
        $taxonomy = '';
        $field = '';
        $fieldType = '';
        $param = '';
        $presets = [];

        if ($type === 'taxonomy') {
            $taxonomy = self::matching($raw['taxonomy'] ?? null, self::TAXONOMY_RE);
            if ($taxonomy === '' || !in_array($ui, self::PICK_UI, true)) {
                return null;
            }
        } elseif ($type === 'field') {
            $field = self::matching($raw['field'] ?? null, self::NAME_RE);
            $fieldType = $field === '' ? '' : (string) ($fieldTypes[$field] ?? '');
            $isOptions = in_array($fieldType, ['select', 'boolean'], true) && in_array($ui, self::PICK_UI, true);
            $isRange = in_array($fieldType, ['number', 'date'], true) && $ui === 'range';
            if (!$isOptions && !$isRange) {
                return null;
            }
        } elseif ($type === 'date') {
            if (($raw['field'] ?? null) !== null) {
                $field = self::matching($raw['field'], self::NAME_RE);
                if ($field === '' || ($fieldTypes[$field] ?? '') !== 'date') {
                    return null;
                }
                $fieldType = 'date';
            }
            if (!in_array($ui, ['pills', 'select', 'range'], true)) {
                return null;
            }
            if ($ui !== 'range') {
                $presets = self::presets($raw['presets'] ?? null);
            }
        } elseif ($type === 'search') {
            if ($ui !== 'text') {
                return null;
            }
        } elseif ($type === 'param') {
            $param = self::matching($raw['param'] ?? null, self::NAME_RE);
            if ($param === '' || !in_array($ui, self::PARAM_UI, true)) {
                return null;
            }
        } else {
            return null;
        }

        return [
            'key' => $key,
            'label' => self::label($raw['label'] ?? null, $key),
            'type' => $type,
            'ui' => $ui,
            'multiple' => ($raw['multiple'] ?? false) === true && in_array($ui, ['pills', 'checkboxes', 'dropdown'], true),
            'taxonomy' => $taxonomy,
            'field' => $field,
            'fieldType' => $fieldType,
            'param' => $param,
            'presets' => $presets,
        ];
    }

    /**
     * @param list<mixed> $raws
     * @param array<string,string> $fieldTypes
     * @return list<array{key:string,label:string,orderby:string,order:string}>
     */
    private static function parseSorts(array $raws, array $fieldTypes, string $source): array
    {
        $sorts = [];
        foreach ($raws as $raw) {
            if (count($sorts) >= self::MAX_SORTS) {
                break;
            }
            if (!is_array($raw) || !self::validKey($raw['key'] ?? null)) {
                continue;
            }
            $key = (string) $raw['key'];
            $orderby = $raw['orderby'] ?? null;
            $order = $raw['order'] ?? null;
            if (
                !self::validOrderby($orderby, $fieldTypes, $source) || !in_array($order, ['asc', 'desc'], true)
                || in_array($key, array_column($sorts, 'key'), true)
            ) {
                continue;
            }
            $sorts[] = [
                'key' => $key,
                'label' => self::label($raw['label'] ?? null, $key),
                'orderby' => (string) $orderby,
                'order' => (string) $order,
            ];
        }

        return $sorts;
    }

    /**
     * @param list<mixed> $raws
     * @return list<string>
     */
    private static function parseViews(array $raws): array
    {
        $views = [];
        foreach ($raws as $view) {
            if (is_string($view) && in_array($view, ['grid', 'rows'], true) && !in_array($view, $views, true)) {
                $views[] = $view;
            }
        }

        return $views === [] ? ['grid'] : $views;
    }

    /** @return list<string> */
    private static function presets(mixed $raw): array
    {
        $presets = [];
        foreach (self::items($raw) as $preset) {
            if (is_string($preset) && in_array($preset, self::PRESETS, true) && !in_array($preset, $presets, true)) {
                $presets[] = $preset;
            }
        }

        return $presets;
    }

    private static function validKey(mixed $value): bool
    {
        return is_string($value) && preg_match(self::KEY_RE, $value) === 1 && !in_array($value, ['sort', 'page'], true);
    }

    /**
     * Ordinamento ammesso: per i posts date, title, menu_order; per i terms name, slug, count. Poi field:<chiave>
     * con un campo number, date, text o select.
     * @param array<string,string> $fieldTypes
     */
    private static function validOrderby(mixed $value, array $fieldTypes, string $source = 'posts'): bool
    {
        if (!is_string($value)) {
            return false;
        }
        if ($source === 'terms' ? in_array($value, self::TERM_ORDERBY, true) : in_array($value, ['date', 'title', 'menu_order'], true)) {
            return true;
        }
        if (!str_starts_with($value, 'field:')) {
            return false;
        }
        $fieldType = $fieldTypes[substr($value, 6)] ?? '';

        return in_array($fieldType, ['number', 'date', 'text', 'select'], true);
    }

    private static function matching(mixed $value, string $pattern): string
    {
        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : '';
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private static function intIn(mixed $value, int $min, int $max, int $default): int
    {
        if (is_string($value) && preg_match('/\A\d{1,3}\z/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= $min && $value <= $max ? $value : $default;
    }

    private static function label(mixed $value, string $fallback): string
    {
        $label = is_string($value) ? trim(wp_strip_all_tags($value)) : '';

        return $label !== '' ? $label : $fallback;
    }

    /** @return list<mixed> */
    private static function items(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $spec chiave → tipo atteso (string, bool, int, list)
     */
    private static function hasTypes(array $data, array $spec): bool
    {
        foreach ($spec as $key => $type) {
            if (!array_key_exists($key, $data)) {
                return false;
            }
            $value = $data[$key];
            $ok = match ($type) {
                'string' => is_string($value),
                'bool' => is_bool($value),
                'int' => is_int($value),
                'list' => is_array($value) && array_is_list($value),
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }
}
