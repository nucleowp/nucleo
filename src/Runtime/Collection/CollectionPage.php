<?php

declare(strict_types=1);

namespace Nucleo\Runtime\Collection;

defined('ABSPATH') || exit;

/** Una pagina di risultati, già disegnata: è il valore in cache. */
final class CollectionPage
{
    /**
     * @param list<string> $items HTML di una scheda per elemento
     * @param array<string,array<string,int>> $counts filtro → valore → numero di risultati
     * @param array<string,list<array{value:string,label:string}>> $options filtro → opzioni mostrate
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pages,
        public readonly array $counts,
        public readonly array $options,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'total' => $this->total,
            'page' => $this->page,
            'pages' => $this->pages,
            'counts' => $this->counts,
            'options' => $this->options,
        ];
    }

    /** @param array<string,mixed> $data null se malformato */
    public static function fromArray(array $data): ?self
    {
        foreach (['items', 'total', 'page', 'pages', 'counts', 'options'] as $key) {
            if (!array_key_exists($key, $data)) {
                return null;
            }
        }

        $items = $data['items'];
        if (!is_array($items) || !array_is_list($items)) {
            return null;
        }
        foreach ($items as $item) {
            if (!is_string($item)) {
                return null;
            }
        }
        if (!is_int($data['total']) || !is_int($data['page']) || !is_int($data['pages'])) {
            return null;
        }

        $counts = $data['counts'];
        if (!is_array($counts)) {
            return null;
        }
        foreach ($counts as $byValue) {
            if (!is_array($byValue)) {
                return null;
            }
            foreach ($byValue as $count) {
                if (!is_int($count)) {
                    return null;
                }
            }
        }

        $options = $data['options'];
        if (!is_array($options)) {
            return null;
        }
        foreach ($options as $list) {
            if (!is_array($list) || !array_is_list($list)) {
                return null;
            }
            foreach ($list as $option) {
                if (!is_array($option) || !is_string($option['value'] ?? null) || !is_string($option['label'] ?? null)) {
                    return null;
                }
            }
        }

        return new self($items, $data['total'], $data['page'], $data['pages'], $counts, $options);
    }
}
