<?php

declare(strict_types=1);

namespace Nucleo\Runtime\Collection;

/**
 * Stato richiesto dal visitatore: solo filtri dichiarati, valori sanificati.
 * values[key]: list<string> per i filtri a opzioni, i preset di data e search (un solo testo);
 * array{min:string,max:string} per ui "range" (stringa vuota = estremo assente).
 */
final class CollectionState
{
    private const VALUE_RE = '/\A[A-Za-z0-9_-]{1,64}\z/';
    private const DATE_RE = '/\A\d{4}-\d{2}-\d{2}\z/';
    private const NUMBER_RE = '/\A-?\d{1,15}(\.\d{1,6})?\z/';
    private const PAGE_RE = '/\A\d{1,3}\z/';
    private const SEARCH_RE = '/\A(.{0,100})/su';

    /** @param array<string,list<string>|array{min:string,max:string}> $values */
    public function __construct(
        public readonly array $values,
        public readonly ?string $sort,
        public readonly int $page,
    ) {
    }

    /** @param array<string,mixed> $params $_GET o parametri REST, non sanificati */
    public static function fromRequest(CollectionConfig $config, array $params): self
    {
        $values = [];
        foreach ($config->filters as $filter) {
            $key = $filter['key'];
            $name = $config->param($key);
            if ($filter['ui'] === 'range') {
                $range = self::parseRange($config, $filter, $params);
                if ($range !== null) {
                    $values[$key] = $range;
                }
            } elseif ($filter['type'] === 'search' || $filter['ui'] === 'text') {
                // Ricerca e param con ui text: stesso testo sanificato (spazi e accenti ammessi).
                $text = self::parseSearch($params[$name] ?? null);
                if ($text !== '') {
                    $values[$key] = [$text];
                }
            } else {
                $options = self::parseOptions($filter, $params[$name] ?? null);
                if ($options !== []) {
                    $values[$key] = $options;
                }
            }
        }

        return new self($values, self::parseSort($config, $params), self::parsePage($config, $params));
    }

    /** Nessun filtro attivo (sort e page non contano). */
    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /** Forma canonica e stabile, per la chiave di cache. */
    public function key(): string
    {
        $values = $this->values;
        ksort($values);
        foreach ($values as $name => $value) {
            if (array_key_exists('min', $value)) {
                $values[$name] = ['min' => $value['min'], 'max' => $value['max']];
            } else {
                sort($value);
                $values[$name] = $value;
            }
        }

        return (string) json_encode(['values' => $values, 'sort' => $this->sort, 'page' => $this->page]);
    }

    /** Stato senza un filtro (per i conteggi delle faccette). */
    public function without(string $filterKey): self
    {
        $values = $this->values;
        unset($values[$filterKey]);

        return new self($values, $this->sort, $this->page);
    }

    /**
     * Parametri URL dello stato, con la pagina indicata (null = la corrente; pagina 1 omessa).
     * @return array<string,string|list<string>>
     */
    public function query(CollectionConfig $config, ?int $page = null): array
    {
        $query = [];
        foreach ($this->values as $key => $value) {
            $name = (string) $key;
            $filter = $config->filter($name);
            if ($filter === null) {
                continue;
            }
            if (array_is_list($value)) {
                $query[$config->param($name)] = $filter['type'] === 'search' || $filter['ui'] === 'text' ? ($value[0] ?? '') : $value;
            } else {
                if ($value['min'] !== '') {
                    $query[$config->param($name . '_min')] = $value['min'];
                }
                if ($value['max'] !== '') {
                    $query[$config->param($name . '_max')] = $value['max'];
                }
            }
        }
        if ($this->sort !== null) {
            $query[$config->param('sort')] = $this->sort;
        }
        $current = $page ?? $this->page;
        if ($current > 1) {
            $query[$config->param('page')] = (string) $current;
        }

        return $query;
    }

    /**
     * @param array{key:string,label:string,type:string,ui:string,multiple:bool,taxonomy:string,field:string,fieldType:string,param:string,presets:list<string>} $filter
     * @return list<string>
     */
    private static function parseOptions(array $filter, mixed $raw): array
    {
        $candidates = is_array($raw) ? $raw : [$raw];
        $options = [];
        foreach ($candidates as $value) {
            if (!is_string($value) || preg_match(self::VALUE_RE, $value) !== 1) {
                continue;
            }
            if ($filter['type'] === 'date' && !in_array($value, $filter['presets'], true)) {
                continue;
            }
            if (!in_array($value, $options, true)) {
                $options[] = $value;
            }
        }
        if (!$filter['multiple']) {
            $options = array_slice($options, 0, 1);
        }

        return $options;
    }

    private static function parseSearch(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $text = trim(wp_strip_all_tags($raw));
        if (preg_match(self::SEARCH_RE, $text, $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }

    /**
     * @param array{key:string,label:string,type:string,ui:string,multiple:bool,taxonomy:string,field:string,fieldType:string,param:string,presets:list<string>} $filter
     * @param array<string,mixed> $params $_GET o parametri REST, non sanificati
     * @return array{min:string,max:string}|null
     */
    private static function parseRange(CollectionConfig $config, array $filter, array $params): ?array
    {
        $isDate = $filter['type'] === 'date' || $filter['fieldType'] === 'date';
        $min = self::parseBound($params[$config->param($filter['key'] . '_min')] ?? null, $isDate);
        $max = self::parseBound($params[$config->param($filter['key'] . '_max')] ?? null, $isDate);
        if ($min === '' && $max === '') {
            return null;
        }

        return ['min' => $min, 'max' => $max];
    }

    private static function parseBound(mixed $raw, bool $isDate): string
    {
        if (is_int($raw)) {
            $raw = (string) $raw;
        }
        if (!is_string($raw)) {
            return '';
        }
        $value = trim($raw);
        if ($isDate) {
            if (preg_match(self::DATE_RE, $value) !== 1) {
                return '';
            }
            [$year, $month, $day] = array_map('intval', explode('-', $value));

            return checkdate($month, $day, $year) ? $value : '';
        }

        return preg_match(self::NUMBER_RE, $value) === 1 ? $value : '';
    }

    /** @param array<string,mixed> $params */
    private static function parseSort(CollectionConfig $config, array $params): ?string
    {
        $raw = $params[$config->param('sort')] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        foreach ($config->sorts as $sort) {
            if ($sort['key'] === $raw) {
                return $raw;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $params */
    private static function parsePage(CollectionConfig $config, array $params): int
    {
        $raw = $params[$config->param('page')] ?? null;
        if (is_int($raw)) {
            $raw = (string) $raw;
        }
        if (!is_string($raw) || preg_match(self::PAGE_RE, $raw) !== 1) {
            return 1;
        }
        $page = (int) $raw;

        return $page >= 1 && $page <= 100 ? $page : 1;
    }
}
