<?php

declare(strict_types=1);

namespace Nucleo\Runtime\Collection;

defined('ABSPATH') || exit;

use Nucleo\Content\FieldValues;
use Nucleo\Content\ModelRepository;
use Nucleo\Extend\Components;
use Nucleo\Runtime\CardRenderer;
use Nucleo\Schema\JsonSchemaValidator;
use WP_Query;
use WP_Term;

/** Esegue la collezione: query, schede disegnate con CardRenderer, faccette e opzioni dei filtri. */
final class CollectionQuery
{
    private const COUNT_LIMIT = 1000;
    private const TERM_LIMIT = 100;
    private const MAX_PAGES = 100;
    /** Tipi di contenuto di sistema, mai in una collezione anche se pubblici. */
    private const HIDDEN_TYPES = ['nucleo_section', 'nucleo_layout', 'nucleo_submission', 'wp_block', 'shop_coupon', 'shop_order'];

    /** @param array<string,mixed> $template modello della scheda {html, slots} */
    public static function page(CollectionConfig $config, CollectionState $state, array $template): CollectionPage
    {
        if ($config->source === 'component') {
            return self::componentPage($config, $state, $template);
        }
        if ($config->source === 'terms') {
            return self::termPage($config, $state, $template);
        }
        if ($config->source !== 'posts' || !self::postTypeAllowed($config->postType)) {
            return new CollectionPage([], 0, 1, 1, [], []);
        }

        $page = max(1, $state->page);
        $query = new WP_Query(self::args($config, $state, $page));
        $items = [];
        $posts = is_array($query->posts) ? $query->posts : [];
        foreach ($posts as $post) {
            if ($post instanceof \WP_Post) {
                $items[] = CardRenderer::render($template, $post);
            }
        }
        $total = (int) $query->found_posts;
        $options = self::options($config);

        return new CollectionPage(
            $items,
            $total,
            $page,
            min(self::MAX_PAGES, max(1, (int) ceil($total / $config->perPage))),
            $config->counts ? self::counts($config, $state, $options) : [],
            $options,
        );
    }

    /** Tipo di contenuto ammesso in una collezione: pubblico (viewable) e non di sistema. */
    public static function postTypeAllowed(string $postType): bool
    {
        return is_post_type_viewable($postType) && !in_array($postType, self::HIDDEN_TYPES, true);
    }

    /** Tassonomia ammessa in una collezione di termini: registrata, pubblica, non nav_menu né wp_*. */
    public static function taxonomyAllowed(string $taxonomy): bool
    {
        return $taxonomy !== ''
            && taxonomy_exists($taxonomy)
            && is_taxonomy_viewable($taxonomy)
            && $taxonomy !== 'nav_menu'
            && !str_starts_with($taxonomy, 'wp_');
    }

    /**
     * Sorgente terms: i termini della tassonomia, ognuno disegnato come scheda. Niente filtri né conteggi.
     * @param array<string,mixed> $template
     */
    private static function termPage(CollectionConfig $config, CollectionState $state, array $template): CollectionPage
    {
        $empty = new CollectionPage([], 0, 1, 1, [], []);
        if (!self::taxonomyAllowed($config->taxonomy)) {
            return $empty;
        }
        $parent = self::parentArgs($config);
        if ($parent === null) {
            return $empty;
        }

        // Stessi argomenti per elenco e totale. hierarchical false: get_terms non aggiunge i padri vuoti con figli
        // pieni, che fields=count non conterebbe; il meta_key dell'ordinamento esclude gli stessi termini in entrambi.
        $args = array_merge(
            ['taxonomy' => $config->taxonomy, 'hide_empty' => $config->hideEmpty, 'hierarchical' => false],
            $parent,
            self::termOrderArgs($config),
        );
        $count = get_terms(array_merge($args, ['fields' => 'count']));
        $total = is_numeric($count) ? (int) $count : 0;

        $page = max(1, $state->page);
        $terms = get_terms(array_merge($args, [
            'number' => $config->perPage,
            'offset' => ($page - 1) * $config->perPage,
        ]));
        $terms = is_array($terms) ? array_values(array_filter($terms, static fn (mixed $t): bool => $t instanceof WP_Term)) : [];
        self::primeTermImages($config->taxonomy, $terms);
        $items = [];
        foreach ($terms as $term) {
            $items[] = CardRenderer::render($template, $term);
        }

        return new CollectionPage(
            $items,
            $total,
            $page,
            min(self::MAX_PAGES, max(1, (int) ceil($total / $config->perPage))),
            [],
            [],
        );
    }

    /**
     * Allegati dei campi image dei termini in una sola query, prima delle schede (get_terms ha già letto le term meta).
     * @param list<WP_Term> $terms
     */
    private static function primeTermImages(string $taxonomy, array $terms): void
    {
        $keys = [];
        foreach (FieldValues::fieldsFor(['taxonomy' => $taxonomy]) as $field) {
            if (($field['type'] ?? null) === 'image' && is_string($field['key'] ?? null)) {
                $keys[] = $field['key'];
            }
        }
        $ids = [];
        foreach ($keys === [] ? [] : $terms as $term) {
            foreach ($keys as $key) {
                $id = get_term_meta($term->term_id, $key, true);
                if (is_numeric($id) && (int) $id > 0) {
                    $ids[] = (int) $id;
                }
            }
        }
        if ($ids !== []) {
            _prime_post_caches(array_values(array_unique($ids)), false, true);
        }
    }

    /**
     * Filtro parent di get_terms: vuoto per tutti, 0 per il primo livello, id del termine per un slug.
     * Null se lo slug non esiste: la pagina è vuota.
     * @return array<string,int>|null
     */
    private static function parentArgs(CollectionConfig $config): ?array
    {
        if ($config->parent === '') {
            return [];
        }
        if ($config->parent === '0') {
            return ['parent' => 0];
        }
        $term = get_term_by('slug', $config->parent, $config->taxonomy);

        return $term instanceof WP_Term ? ['parent' => (int) $term->term_id] : null;
    }

    /** @return array<string,mixed> */
    private static function termOrderArgs(CollectionConfig $config): array
    {
        $order = strtoupper($config->order);
        if (str_starts_with($config->orderby, 'field:')) {
            $name = substr($config->orderby, 6);
            $field = self::taxonomyField($config->taxonomy, $name);

            return [
                'meta_key' => $name,
                'orderby' => ($field['type'] ?? null) === 'number' ? 'meta_value_num' : 'meta_value',
                'order' => $order,
            ];
        }

        return ['orderby' => $config->orderby, 'order' => $order];
    }

    /**
     * Sorgente component (livello 4): i parametri dei filtri param passano dallo schema della DataSource.
     * @param array<string,mixed> $template
     */
    private static function componentPage(CollectionConfig $config, CollectionState $state, array $template): CollectionPage
    {
        $empty = new CollectionPage([], 0, 1, 1, [], []);
        $source = Components::instance()->dataSource($config->component);
        if ($source === null) {
            return $empty;
        }
        $schema = $source->schema();
        $paramSchemas = $schema['params'];
        $params = self::params($config, $state, $paramSchemas);
        $errors = (new JsonSchemaValidator())->validate($params, ['type' => 'object', 'properties' => $paramSchemas]);
        if ($errors !== []) {
            return $empty;
        }

        $page = max(1, $state->page);
        $data = $source->read($params, $page, $config->perPage);
        $items = [];
        foreach ($data->items as $item) {
            $items[] = CardRenderer::render($template, $item);
        }

        return new CollectionPage(
            $items,
            $data->total,
            $page,
            min(self::MAX_PAGES, max(1, (int) ceil($data->total / $config->perPage))),
            [],
            self::paramOptions($config, $paramSchemas),
        );
    }

    /**
     * Valori dei filtri param nello stato, convertiti al type dello schema del parametro.
     * @param array<string,array<string,mixed>> $paramSchemas
     * @return array<string,mixed>
     */
    private static function params(CollectionConfig $config, CollectionState $state, array $paramSchemas): array
    {
        $params = [];
        foreach ($config->filters as $filter) {
            if ($filter['type'] !== 'param' || !isset($state->values[$filter['key']])) {
                continue;
            }
            $value = $state->values[$filter['key']];
            if ($filter['ui'] === 'range') {
                // Ui range: un parametro per estremo (param_min, param_max), col type dello schema di quella chiave.
                foreach (['min', 'max'] as $bound) {
                    $name = $filter['param'] . '_' . $bound;
                    $def = $paramSchemas[$name] ?? null;
                    if ($def === null || ($value[$bound] ?? '') === '') {
                        continue;
                    }
                    $converted = self::scalar($value[$bound], $def);
                    if ($converted !== null) {
                        $params[$name] = $converted;
                    }
                }
                continue;
            }

            $def = $paramSchemas[$filter['param']] ?? null;
            if ($def === null) {
                continue;
            }
            $values = [];
            foreach ($value as $raw) {
                $converted = self::scalar($raw, $def);
                if ($converted !== null && (!isset($def['enum']) || in_array($converted, $def['enum'], true))) {
                    $values[] = $converted;
                }
            }
            if ($values === []) {
                continue;
            }
            // Ui text: un solo testo, come la ricerca.
            $params[$filter['param']] = $filter['multiple'] && $filter['ui'] !== 'text' ? $values : $values[0];
        }

        return $params;
    }

    /**
     * Stringa dello stato convertita al type dello schema; null se non si converte.
     * @param array<string,mixed> $def
     */
    private static function scalar(mixed $raw, array $def): int|float|bool|string|null
    {
        if (!is_string($raw)) {
            return null;
        }

        return match ($def['type'] ?? 'string') {
            'integer' => preg_match('/\A-?\d{1,15}\z/', $raw) === 1 ? (int) $raw : null,
            'number' => is_numeric($raw) ? (float) $raw : null,
            'boolean' => match ($raw) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => null,
            },
            default => $raw,
        };
    }

    /**
     * Opzioni dei filtri param con enum: {value, label=value}.
     * @param array<string,array<string,mixed>> $paramSchemas
     * @return array<string,list<array{value:string,label:string}>>
     */
    private static function paramOptions(CollectionConfig $config, array $paramSchemas): array
    {
        $options = [];
        foreach ($config->filters as $filter) {
            $enum = $filter['type'] === 'param' ? ($paramSchemas[$filter['param']]['enum'] ?? null) : null;
            if (!is_array($enum)) {
                continue;
            }
            $options[$filter['key']] = array_values(array_map(
                static fn (mixed $value): array => ['value' => (string) $value, 'label' => (string) $value],
                $enum,
            ));
        }

        return $options;
    }

    /** @return array<string,mixed> */
    private static function args(CollectionConfig $config, CollectionState $state, int $page): array
    {
        return array_merge(
            [
                'post_type' => $config->postType,
                'post_status' => 'publish',
                'posts_per_page' => $config->perPage,
                'paged' => $page,
                'ignore_sticky_posts' => true,
                'has_password' => false,
            ],
            self::sortArgs($config, $state),
            self::filterArgs($config, $state),
        );
    }

    /** @return array<string,mixed> */
    private static function sortArgs(CollectionConfig $config, CollectionState $state): array
    {
        $orderby = $config->orderby;
        $order = $config->order;
        foreach ($config->sorts as $sort) {
            if ($state->sort !== null && $sort['key'] === $state->sort) {
                $orderby = $sort['orderby'];
                $order = $sort['order'];
            }
        }

        $args = ['order' => strtoupper($order)];
        if (str_starts_with($orderby, 'field:')) {
            $name = substr($orderby, 6);
            $field = self::modelField($config->postType, $name);
            $args['meta_key'] = $name;
            $args['orderby'] = ($field['type'] ?? null) === 'number' ? 'meta_value_num' : 'meta_value';
        } else {
            $args['orderby'] = $orderby;
        }

        return $args;
    }

    /**
     * Clausole dei filtri: tax_query, meta_query, date_query e s.
     * @return array<string,mixed>
     */
    private static function filterArgs(CollectionConfig $config, CollectionState $state): array
    {
        $tax = [];
        $meta = [];
        $dates = [];
        $args = [];
        foreach ($state->values as $name => $value) {
            $name = (string) $name;
            $filter = $config->filter($name);
            if ($filter === null) {
                continue;
            }
            if (isset($value['min'], $value['max'])) {
                // Ui range: array{min,max}, stringa vuota = estremo assente.
                if ($filter['type'] === 'field') {
                    $meta[] = self::rangeClause($filter, $value);
                } elseif ($filter['type'] === 'date') {
                    if ($filter['field'] === '') {
                        $dates[] = self::dateRange($value);
                    } else {
                        $meta[] = self::rangeClause($filter, $value);
                    }
                }
                continue;
            }

            // Valori a lista: opzioni, preset di data o testo di ricerca.
            $value = array_values(array_filter($value, 'is_string'));
            if ($value === []) {
                continue;
            }
            if ($filter['type'] === 'taxonomy') {
                $tax[] = ['taxonomy' => $filter['taxonomy'], 'field' => 'slug', 'terms' => $value, 'operator' => 'IN'];
            } elseif ($filter['type'] === 'field') {
                $meta[] = self::optionClause($config->postType, $filter, $value);
            } elseif ($filter['type'] === 'date') {
                $clause = self::group(array_map(
                    static fn (string $preset): array => $filter['field'] === ''
                        ? self::presetDateQuery($preset)
                        : self::presetMeta($filter['field'], $preset),
                    $value,
                ));
                if ($filter['field'] === '') {
                    $dates[] = $clause;
                } else {
                    $meta[] = $clause;
                }
            } elseif ($filter['type'] === 'search') {
                $args['s'] = $value[0];
            }
        }

        if ($tax !== []) {
            $args['tax_query'] = ['relation' => 'AND', ...$tax];
        }
        if ($meta !== []) {
            $args['meta_query'] = ['relation' => 'AND', ...$meta];
        }
        if ($dates !== []) {
            $args['date_query'] = ['relation' => 'AND', ...$dates];
        }

        return $args;
    }

    /**
     * @param array<string,mixed> $filter
     * @param array{min:string,max:string} $range
     * @return array<string,mixed>
     */
    private static function rangeClause(array $filter, array $range): array
    {
        $clause = [
            'key' => $filter['field'],
            'type' => $filter['fieldType'] === 'date' ? 'DATE' : 'NUMERIC',
        ];
        if ($range['min'] !== '' && $range['max'] !== '') {
            return $clause + ['value' => [$range['min'], $range['max']], 'compare' => 'BETWEEN'];
        }
        if ($range['min'] !== '') {
            return $clause + ['value' => $range['min'], 'compare' => '>='];
        }

        return $clause + ['value' => $range['max'], 'compare' => '<='];
    }

    /**
     * Select e boolean: il select multiplo del modello salva un array, quindi OR di LIKE sul valore serializzato.
     * @param array<string,mixed> $filter
     * @param list<string> $values
     * @return array<int|string,mixed>
     */
    private static function optionClause(string $postType, array $filter, array $values): array
    {
        $key = $filter['field'];
        $field = self::modelField($postType, $key);
        if ($filter['fieldType'] === 'select' && ($field['multiple'] ?? false) === true) {
            global $wpdb;

            return self::group(array_map(
                static fn (string $value): array => ['key' => $key, 'value' => '"' . $wpdb->esc_like($value) . '"', 'compare' => 'LIKE'],
                $values,
            ));
        }
        if (count($values) === 1) {
            return ['key' => $key, 'value' => $values[0], 'compare' => '='];
        }

        return ['key' => $key, 'value' => $values, 'compare' => 'IN'];
    }

    /**
     * @param array{min:string,max:string} $range
     * @return array<string,mixed>
     */
    private static function dateRange(array $range): array
    {
        $clause = ['inclusive' => true];
        if ($range['min'] !== '') {
            $clause['after'] = $range['min'];
        }
        if ($range['max'] !== '') {
            $clause['before'] = $range['max'];
        }

        return $clause;
    }

    /**
     * Preset senza campo: date_query sulla data del post.
     * @return array<string,mixed>
     */
    private static function presetDateQuery(string $preset): array
    {
        $today = current_time('Y-m-d');
        if ($preset === 'upcoming') {
            return ['after' => $today, 'inclusive' => true];
        }
        if ($preset === 'past') {
            return ['before' => $today, 'inclusive' => false];
        }

        return ['year' => (int) substr($today, 0, 4), 'month' => (int) substr($today, 5, 2)];
    }

    /**
     * Preset con campo data del tipo: meta_query di tipo DATE.
     * @return array<string,mixed>
     */
    private static function presetMeta(string $field, string $preset): array
    {
        $today = current_time('Y-m-d');
        if ($preset === 'upcoming') {
            return ['key' => $field, 'value' => $today, 'compare' => '>=', 'type' => 'DATE'];
        }
        if ($preset === 'past') {
            return ['key' => $field, 'value' => $today, 'compare' => '<', 'type' => 'DATE'];
        }
        $first = substr($today, 0, 8) . '01';
        $last = (new \DateTimeImmutable($first))->format('Y-m-t');

        return ['key' => $field, 'value' => [$first, $last], 'compare' => 'BETWEEN', 'type' => 'DATE'];
    }

    /**
     * Una clausola sola, oppure OR delle clausole.
     * @param list<array<string,mixed>> $clauses
     * @return array<int|string,mixed>
     */
    private static function group(array $clauses): array
    {
        if (count($clauses) === 1) {
            return $clauses[0];
        }
        if ($clauses === []) {
            return [];
        }

        return ['relation' => 'OR', ...$clauses];
    }

    /**
     * Conteggi per i filtri taxonomy e select/boolean, con gli altri filtri attivi.
     * @param array<string,list<array{value:string,label:string}>> $options
     * @return array<string,array<string,int>>
     */
    private static function counts(CollectionConfig $config, CollectionState $state, array $options): array
    {
        $counts = [];
        foreach ($config->filters as $filter) {
            $key = $filter['key'];
            if ($filter['type'] === 'taxonomy') {
                $ids = self::matchingIds($config, $state->without($key));
                $counts[$key] = self::termCounts($ids, $filter['taxonomy']);
            } elseif ($filter['type'] === 'field' && in_array($filter['fieldType'], ['select', 'boolean'], true)) {
                $ids = self::matchingIds($config, $state->without($key));
                $counts[$key] = self::metaCounts($ids, $filter['field']);
            } else {
                continue;
            }
            // Ogni opzione ha un conteggio, 0 se nessun risultato.
            foreach ($options[$key] ?? [] as $option) {
                $counts[$key][$option['value']] ??= 0;
            }
        }

        return $counts;
    }

    /** @return list<int> */
    private static function matchingIds(CollectionConfig $config, CollectionState $state): array
    {
        $query = new WP_Query(array_merge(
            [
                'post_type' => $config->postType,
                'post_status' => 'publish',
                'posts_per_page' => self::COUNT_LIMIT,
                'fields' => 'ids',
                'orderby' => 'none',
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
                'has_password' => false,
            ],
            self::filterArgs($config, $state),
        ));

        $ids = [];
        $posts = is_array($query->posts) ? $query->posts : [];
        foreach ($posts as $id) {
            if (is_int($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $ids
     * @return array<string,int>
     */
    private static function termCounts(array $ids, string $taxonomy): array
    {
        $counts = [];
        if ($ids === []) {
            return $counts;
        }
        $terms = wp_get_object_terms($ids, $taxonomy, ['fields' => 'all_with_object_id']);
        if (!is_array($terms)) {
            return $counts;
        }
        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $counts[$term->slug] = ($counts[$term->slug] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param list<int> $ids
     * @return array<string,int>
     */
    private static function metaCounts(array $ids, string $field): array
    {
        $counts = [];
        if ($ids === []) {
            return $counts;
        }
        update_meta_cache('post', $ids);
        foreach ($ids as $id) {
            $seen = [];
            foreach (get_post_meta($id, $field, false) as $raw) {
                foreach ((array) $raw as $value) {
                    if (is_scalar($value)) {
                        $seen[(string) $value] = true;
                    }
                }
            }
            foreach (array_keys($seen) as $value) {
                $counts[(string) $value] = ($counts[(string) $value] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** @return array<string,list<array{value:string,label:string}>> */
    private static function options(CollectionConfig $config): array
    {
        $options = [];
        foreach ($config->filters as $filter) {
            $key = $filter['key'];
            if ($filter['type'] === 'taxonomy') {
                $options[$key] = self::termOptions($filter['taxonomy']);
            } elseif ($filter['type'] === 'field' && $filter['fieldType'] === 'select') {
                $options[$key] = self::fieldOptions(self::modelField($config->postType, $filter['field']));
            } elseif ($filter['type'] === 'field' && $filter['fieldType'] === 'boolean') {
                $options[$key] = [['value' => '1', 'label' => $filter['label']]];
            } elseif ($filter['type'] === 'date' && $filter['ui'] !== 'range') {
                $options[$key] = array_map(
                    static fn (string $preset): array => ['value' => $preset, 'label' => self::presetLabel($preset)],
                    $filter['presets'],
                );
            }
        }

        return $options;
    }

    /** @return list<array{value:string,label:string}> */
    private static function termOptions(string $taxonomy): array
    {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true, 'number' => self::TERM_LIMIT]);
        $options = [];
        if (!is_array($terms)) {
            return $options;
        }
        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $options[] = ['value' => $term->slug, 'label' => $term->name];
            }
        }

        return $options;
    }

    /**
     * Opzioni del campo select del modello: voci stringa, oppure {value, label}.
     * @param array<string,mixed>|null $field
     * @return list<array{value:string,label:string}>
     */
    private static function fieldOptions(?array $field): array
    {
        $options = [];
        foreach ($field['options'] ?? [] as $option) {
            if (is_string($option)) {
                $options[] = ['value' => $option, 'label' => $option];
            } elseif (is_array($option) && is_string($option['value'] ?? null)) {
                $options[] = ['value' => $option['value'], 'label' => is_string($option['label'] ?? null) ? $option['label'] : $option['value']];
            }
        }

        return $options;
    }

    private static function presetLabel(string $preset): string
    {
        return match ($preset) {
            'upcoming' => __('Upcoming', 'nucleo'),
            'past' => __('Past', 'nucleo'),
            default => __('This month', 'nucleo'),
        };
    }

    /** @return array<string,mixed>|null */
    private static function modelField(string $postType, string $key): ?array
    {
        return self::fieldAt('postType', $postType, $key);
    }

    /** @return array<string,mixed>|null campo del modello per la tassonomia (location taxonomy) */
    private static function taxonomyField(string $taxonomy, string $key): ?array
    {
        return self::fieldAt('taxonomy', $taxonomy, $key);
    }

    /**
     * Campo del modello di un gruppo localizzato su postType o taxonomy.
     * @return array<string,mixed>|null
     */
    private static function fieldAt(string $location, string $name, string $key): ?array
    {
        foreach (ModelRepository::model()['fieldGroups'] as $group) {
            if (($group['location'][$location] ?? null) !== $name) {
                continue;
            }
            foreach ($group['fields'] as $field) {
                if (($field['key'] ?? null) === $key) {
                    return $field;
                }
            }
        }

        return null;
    }
}
