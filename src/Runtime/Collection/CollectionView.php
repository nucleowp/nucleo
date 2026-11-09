<?php

declare(strict_types=1);

namespace Nucleo\Runtime\Collection;

/**
 * Markup della collezione (docs/15-dati-dinamici.md, "Markup").
 * $data: dati dello slot {node, config, template, empty, count, reset, countClass, resetClass, labels, class}.
 */
final class CollectionView
{
    /** Preset del filtro data: con etichetta in labels, sostituisce il testo dell'opzione. */
    private const PRESETS = ['upcoming', 'past', 'month'];

    /** @param array<string,mixed> $data */
    public static function render(array $data, CollectionConfig $config, CollectionState $state, CollectionPage $page, int $doc): string
    {
        $extra = trim(is_string($data['class'] ?? null) ? $data['class'] : '');
        $class = trim('nc-collection ' . $extra);
        if ($config->mobileFilters === 'drawer') {
            $class .= ' nc-collection--drawer';
        }
        if ($config->cardSize === 'small') {
            $class .= ' nc-collection--small';
        }
        $view = $config->views[0];

        $attrs = ' data-nc-collection="' . self::e($config->node) . '"'
            . ' data-doc="' . $doc . '"'
            . ' data-prefix="' . self::e($config->prefix) . '"'
            . ' data-view="' . self::e($view) . '"';
        if ($doc > 0) {
            $attrs .= ' data-endpoint="' . self::e(rest_url('nucleo/v1/collection/' . $doc . '/' . $config->node)) . '"';
        }

        $panelId = 'nc-' . $config->node . '-panel';
        $reset = is_string($data['reset'] ?? null)
            ? '<a class="' . self::e(self::className($data, 'resetClass', 'nc-collection-reset')) . '" href="?">' . self::e($data['reset'] !== '' ? $data['reset'] : __('Reset filters', 'nucleo')) . '</a>'
            : '';

        return '<div class="' . self::e($class) . '"' . $attrs . '>'
            . '<form class="nc-collection__form" method="get" action="">'
            . '<button type="button" class="nc-collection__open" aria-expanded="false" aria-controls="' . self::e($panelId) . '" hidden>'
            . self::e(self::label($data, 'filters')) . '</button>'
            . '<div class="nc-collection__panel" id="' . self::e($panelId) . '">'
            . '<button type="button" class="nc-collection__close" hidden>' . self::e(self::label($data, 'close')) . '</button>'
            . self::filters($data, $config, $state, $page)
            . $reset
            . '<button type="submit" class="nc-collection__apply">' . self::e(self::label($data, 'apply')) . '</button>'
            . '</div>'
            . '<div class="nc-collection__bar">'
            . '<p class="' . self::e(self::className($data, 'countClass', 'nc-collection-count')) . '">' . self::e(self::countText($data, $page->total)) . '</p>'
            . self::sortControl($data, $config, $state)
            . self::viewControl($data, $config)
            . '</div>'
            . '</form>'
            . '<div class="nc-collection__results" tabindex="-1">'
            . self::results($data, $config, $state, $page)
            . '</div>'
            . '<p class="nc-collection__status" role="status" aria-live="polite"></p>'
            . '</div>';
    }

    /**
     * Contenuto di .nc-collection__results: elenco o stato vuoto, poi il pager. Con $append solo gli <li> e il pager.
     * @param array<string,mixed> $data
     */
    public static function results(array $data, CollectionConfig $config, CollectionState $state, CollectionPage $page, bool $append = false): string
    {
        $html = '';
        if ($page->items === []) {
            if (!$append) {
                $empty = is_string($data['empty'] ?? null) ? $data['empty'] : '';
                $html .= '<div class="nc-collection__empty">' . ($empty !== '' ? $empty : '<p>' . esc_html__('No results', 'nucleo') . '</p>') . '</div>';
            }
        } else {
            $items = '';
            foreach ($page->items as $item) {
                $items .= '<li class="nc-collection__item">' . $item . '</li>';
            }
            $html .= $append ? $items : '<ul class="nc-collection__items" role="list">' . $items . '</ul>';
        }

        if ($config->paging === 'pages' && $page->pages > 1) {
            $html .= self::pager($config, $state, $page);
        } elseif ($config->paging === 'more' && $page->page < $page->pages) {
            // Dentro .nc-collection__pager: lo script lo intercetta e lo sostituisce come il pager.
            $html .= '<div class="nc-collection__pager nc-collection__pager--more"><a class="nc-collection__more" href="'
                . self::href($config, $state, $page->page + 1) . '">' . self::e(self::label($data, 'more')) . '</a></div>';
        }

        return $html;
    }

    /**
     * Testo del conteggio: count.one con 1, count.text altrimenti; senza figlio count, labels.results;
     * senza testi propri, la forma plurale tradotta (_n). I testi passano da fill().
     * @param array<string,mixed> $data
     */
    public static function countText(array $data, int $total): string
    {
        $count = $data['count'] ?? null;
        if (is_array($count)) {
            $one = is_string($count['one'] ?? null) ? $count['one'] : '';
            $text = is_string($count['text'] ?? null) ? $count['text'] : '';
            if ($total === 1 && $one !== '') {
                return self::fill($one, 1, false);
            }
            if ($text !== '') {
                return self::fill($text, $total);
            }
        }

        $label = self::rawLabel($data, 'results');
        if ($label === '') {
            /* translators: %d: number of results. */
            return sprintf(_n('%d result', '%d results', $total, 'nucleo'), $total);
        }

        return self::fill($label, $total);
    }

    /**
     * Inserisce il numero nel testo.
     * - Due forme "singolare|plurale" (un solo | non preceduto da \): la prima con 1 risultato, la seconda con gli altri.
     *   Con più di un | il testo è una forma sola. \| è un | letterale.
     * - {count} e %d diventano il numero; %% è un % letterale.
     * - Una forma senza segnaposto riceve il numero davanti ("prodotti" con 12 diventa "12 prodotti"). Fa eccezione
     *   la forma singolare quando la plurale ha un segnaposto: "Un prodotto|%d prodotti" con 1 resta "Un prodotto".
     *   Con $prefix false (count.one) il numero non va mai davanti.
     */
    public static function fill(string $template, int $total, bool $prefix = true): string
    {
        $forms = preg_split('/(?<!\\\\)\|/', $template) ?: [$template];
        $text = $template;
        if (count($forms) === 2) {
            $text = trim($total === 1 ? $forms[0] : $forms[1]);
            if ($total === 1 && self::hasPlaceholder($forms[1])) {
                $prefix = false;
            }
        }
        $text = str_replace('\\|', '|', $text);
        $number = (string) $total;
        if (!self::hasPlaceholder($text)) {
            $text = str_replace('%%', '%', $text);

            return $prefix ? trim($number . ' ' . $text) : $text;
        }

        return (string) preg_replace_callback('/%%|%d|\{count\}/', static fn (array $m): string => $m[0] === '%%' ? '%' : $number, $text);
    }

    /** {count} o %d non preceduto da un altro % (%%d è "%" seguito da "d"). */
    private static function hasPlaceholder(string $text): bool
    {
        return str_contains($text, '{count}') || preg_match('/(?<!%)(?:%%)*%d/', $text) === 1;
    }

    /**
     * Etichetta da labels; vuota o assente, il testo predefinito tradotto a ogni richiesta (lingua del visitatore,
     * non di chi ha salvato). I preset delle date senza testo restano vuoti: vale l'etichetta dell'opzione.
     * @param array<string,mixed> $data
     */
    private static function label(array $data, string $key): string
    {
        $labels = $data['labels'] ?? [];
        $text = is_array($labels) && is_string($labels[$key] ?? null) ? $labels[$key] : '';

        return $text !== '' ? $text : self::defaultLabel($key);
    }

    /** @param array<string,mixed> $data */
    private static function rawLabel(array $data, string $key): string
    {
        $labels = $data['labels'] ?? [];

        return is_array($labels) && is_string($labels[$key] ?? null) ? $labels[$key] : '';
    }

    private static function defaultLabel(string $key): string
    {
        return match ($key) {
            'filters' => __('Filters', 'nucleo'),
            'apply' => __('Apply', 'nucleo'),
            'more' => __('Load more', 'nucleo'),
            'sort' => __('Sort by', 'nucleo'),
            'close' => __('Close', 'nucleo'),
            'all' => __('All', 'nucleo'),
            'from' => __('From', 'nucleo'),
            'to' => __('To', 'nucleo'),
            'grid' => __('Grid', 'nucleo'),
            'rows' => __('Rows', 'nucleo'),
            default => '',
        };
    }

    /**
     * Classe del nodo figlio (countClass, resetClass); senza figlio o con classe vuota, la classe di base.
     * @param array<string,mixed> $data
     */
    private static function className(array $data, string $key, string $fallback): string
    {
        $value = $data[$key] ?? null;
        $class = is_string($value) ? trim($value) : '';

        return $class !== '' ? $class : $fallback;
    }

    /**
     * Testo di un'opzione: per i filtri data, labels[valore preset] se non vuota.
     * @param array<string,mixed> $data
     */
    private static function optionLabel(array $data, string $type, string $value, string $label): string
    {
        if ($type === 'date' && in_array($value, self::PRESETS, true)) {
            $text = self::label($data, $value);
            if ($text !== '') {
                return $text;
            }
        }

        return $label;
    }

    /** @param array<string,mixed> $data */
    private static function filters(array $data, CollectionConfig $config, CollectionState $state, CollectionPage $page): string
    {
        $html = '';
        foreach ($config->filters as $filter) {
            $html .= self::filterControl($data, $filter, $config, $state, $page);
        }

        return $html;
    }

    /**
     * @param array<string,mixed> $data
     * @param array{key:string,label:string,type:string,ui:string,multiple:bool,taxonomy:string,field:string,fieldType:string,param:string,presets:list<string>} $filter
     */
    private static function filterControl(array $data, array $filter, CollectionConfig $config, CollectionState $state, CollectionPage $page): string
    {
        $key = $filter['key'];
        $name = $config->param($key);
        $label = self::e($filter['label']);
        $chosen = self::chosen($state, $key);

        if ($filter['ui'] === 'range') {
            return self::rangeControl($data, $filter, $config, $state);
        }

        if ($filter['type'] === 'search' || $filter['ui'] === 'text') {
            $type = $filter['type'] === 'search' ? 'search' : 'text';
            $maxlength = $type === 'search' ? ' maxlength="100"' : '';

            return '<fieldset class="nc-collection__filter" data-filter="' . self::e($key) . '"><legend>' . $label . '</legend>'
                . '<input type="' . $type . '" name="' . self::e($name) . '" value="' . self::e($chosen[0] ?? '') . '"' . $maxlength . '>'
                . '</fieldset>';
        }

        $all = self::label($data, 'all');

        if ($filter['ui'] === 'select') {
            $options = '<option value=""' . ($chosen === [] ? ' selected' : '') . '>' . self::e($all) . '</option>';
            foreach (self::options($page, $key) as $option) {
                $selected = in_array($option['value'], $chosen, true) ? ' selected' : '';
                $text = self::optionLabel($data, $filter['type'], $option['value'], $option['label']);
                $options .= '<option value="' . self::e($option['value']) . '"' . $selected . '>' . self::e($text) . '</option>';
            }

            return '<label class="nc-collection__filter" data-filter="' . self::e($key) . '"><span>' . $label . '</span> '
                . '<select name="' . self::e($name) . '">' . $options . '</select></label>';
        }

        $multiple = $filter['multiple'];
        $type = $multiple ? 'checkbox' : 'radio';
        $inputName = $multiple ? $name . '[]' : $name;
        $class = $filter['ui'] === 'pills' ? 'nc-collection__pill' : 'nc-collection__option';
        $controls = '';
        if (!$multiple) {
            $controls .= self::control('radio', $name, '', $all, $chosen === [], null, $class);
        }
        foreach (self::options($page, $key) as $option) {
            $controls .= self::control(
                $type,
                $inputName,
                $option['value'],
                self::optionLabel($data, $filter['type'], $option['value'], $option['label']),
                in_array($option['value'], $chosen, true),
                self::count($config, $page, $key, $option['value']),
                $class,
            );
        }

        if ($filter['ui'] === 'dropdown') {
            return '<details class="nc-collection__dropdown" data-filter="' . self::e($key) . '"><summary>' . $label . '</summary>'
                . '<div class="nc-collection__menu">' . $controls . '</div></details>';
        }

        return '<fieldset class="nc-collection__filter" data-filter="' . self::e($key) . '"><legend>' . $label . '</legend>'
            . $controls . '</fieldset>';
    }

    /**
     * @param array<string,mixed> $data
     * @param array{key:string,label:string,type:string,ui:string,multiple:bool,taxonomy:string,field:string,fieldType:string,param:string,presets:list<string>} $filter
     */
    private static function rangeControl(array $data, array $filter, CollectionConfig $config, CollectionState $state): string
    {
        $key = $filter['key'];
        $isDate = $filter['type'] === 'date' || $filter['fieldType'] === 'date';
        $type = $isDate ? 'date' : 'number';
        $step = $isDate ? '' : ' step="any"';
        $value = $state->values[$key] ?? null;
        $min = is_array($value) && array_key_exists('min', $value) ? $value['min'] : '';
        $max = is_array($value) && array_key_exists('max', $value) ? $value['max'] : '';

        return '<fieldset class="nc-collection__filter nc-collection__range" data-filter="' . self::e($key) . '"><legend>' . self::e($filter['label']) . '</legend>'
            . '<label>' . self::e(self::label($data, 'from')) . ' <input type="' . $type . '" name="' . self::e($config->param($key . '_min')) . '" value="' . self::e($min) . '"' . $step . '></label> '
            . '<label>' . self::e(self::label($data, 'to')) . ' <input type="' . $type . '" name="' . self::e($config->param($key . '_max')) . '" value="' . self::e($max) . '"' . $step . '></label>'
            . '</fieldset>';
    }

    private static function control(string $type, string $name, string $value, string $label, bool $checked, ?int $count, string $class): string
    {
        $disabled = $count === 0 && !$checked;
        $html = '<label class="' . $class . '"><input type="' . $type . '" name="' . self::e($name) . '" value="' . self::e($value) . '"'
            . ($checked ? ' checked' : '') . ($disabled ? ' disabled' : '') . '> ' . self::e($label);
        if ($count !== null) {
            $html .= ' <span data-count="' . self::e($value) . '">(' . $count . ')</span>';
        }

        return $html . '</label>';
    }

    /** @param array<string,mixed> $data */
    private static function sortControl(array $data, CollectionConfig $config, CollectionState $state): string
    {
        if ($config->sorts === []) {
            return '';
        }
        $selected = $state->sort;
        if ($selected === null) {
            foreach ($config->sorts as $sort) {
                if ($sort['orderby'] === $config->orderby && $sort['order'] === $config->order) {
                    $selected = $sort['key'];
                    break;
                }
            }
        }
        $options = '';
        foreach ($config->sorts as $sort) {
            $options .= '<option value="' . self::e($sort['key']) . '"' . ($sort['key'] === $selected ? ' selected' : '') . '>'
                . self::e($sort['label']) . '</option>';
        }

        return '<label class="nc-collection__sort">' . self::e(self::label($data, 'sort')) . ' <select name="' . self::e($config->param('sort')) . '">' . $options . '</select></label>';
    }

    /** @param array<string,mixed> $data */
    private static function viewControl(array $data, CollectionConfig $config): string
    {
        if (count($config->views) !== 2) {
            return '';
        }
        $current = $config->views[0];
        $buttons = '';
        foreach ($config->views as $view) {
            $pressed = $view === $current ? 'true' : 'false';
            $buttons .= '<button type="button" class="nc-collection__view" data-view="' . self::e($view) . '" aria-pressed="' . $pressed . '">'
                . self::e(self::label($data, $view)) . '</button>';
        }

        return '<div class="nc-collection__views" role="group" hidden>' . $buttons . '</div>';
    }

    private static function pager(CollectionConfig $config, CollectionState $state, CollectionPage $page): string
    {
        // Prima, ultima e pagina corrente ±2; un salto tra due numeri mostrato come gap.
        $links = '';
        $previous = 0;
        for ($n = 1; $n <= $page->pages; $n++) {
            if ($n !== 1 && $n !== $page->pages && abs($n - $page->page) > 2) {
                continue;
            }
            if ($previous > 0 && $n - $previous > 1) {
                $links .= '<span class="nc-collection__gap">…</span>';
            }
            $current = $n === $page->page ? ' aria-current="page"' : '';
            $links .= '<a href="' . self::href($config, $state, $n) . '"' . $current . '>' . $n . '</a>';
            $previous = $n;
        }

        return '<nav class="nc-collection__pager" aria-label="' . esc_attr__('Pages', 'nucleo') . '">' . $links . '</nav>';
    }

    /** @return list<array{value:string,label:string}> */
    private static function options(CollectionPage $page, string $key): array
    {
        return $page->options[$key] ?? [];
    }

    /** @return list<string> */
    private static function chosen(CollectionState $state, string $key): array
    {
        $value = $state->values[$key] ?? [];

        return is_array($value) && !array_key_exists('min', $value) ? array_values($value) : [];
    }

    private static function count(CollectionConfig $config, CollectionPage $page, string $key, string $value): ?int
    {
        if (!$config->counts) {
            return null;
        }
        $count = $page->counts[$key][$value] ?? null;

        return is_int($count) ? $count : null;
    }

    /** Attributo href già escapato: "?" più i parametri dello stato per la pagina indicata. */
    private static function href(CollectionConfig $config, CollectionState $state, int $page): string
    {
        return self::e('?' . http_build_query($state->query($config, $page)));
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
