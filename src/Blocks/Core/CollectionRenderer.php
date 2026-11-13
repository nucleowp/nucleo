<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\CardRules;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\NodeValidator;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;
use Nucleo\Content\ModelRepository;
use Nucleo\Extend\Components;
use Nucleo\Runtime\Collection\CollectionConfig;
use Nucleo\Runtime\Collection\CollectionQuery;
use Nucleo\Runtime\Collection\CollectionState;
use Nucleo\Runtime\Collection\CollectionView;
use Nucleo\Schema\ValidationError;

/**
 * nc/collection (docs/15-dati-dinamici.md, livello 2). Al salvataggio compila configurazione, scheda, testi e
 * primo stato senza filtri; lo slot nc/collection disegna la collezione a ogni richiesta.
 */
final class CollectionRenderer implements Renderer, NodeValidator
{
    private const CARD = 'nc/collection-card';
    private const EMPTY = 'nc/collection-empty';
    private const COUNT = 'nc/collection-count';
    private const RESET = 'nc/collection-reset';
    private const PICK_UI = ['pills', 'select', 'checkboxes', 'dropdown'];
    private const SORT_TYPES = ['number', 'date', 'text', 'select'];
    private const PICK_TYPES = ['select', 'boolean'];
    private const RANGE_TYPES = ['number', 'date'];
    /** Blocchi che leggono il post: nella scheda di un termine non ammessi. */
    private const TERM_BLOCKS = ['nc/post-title', 'nc/post-image', 'nc/post-meta', 'nc/repeater'];
    /** Sorgenti di dato legate al post o al ripetitore: nella scheda di un termine non ammesse (si usa term). */
    private const TERM_OTHER_SOURCES = ['post', 'nucleo', 'item', 'author', 'acf', 'metabox', 'jetengine'];
    /** Chiave di labels → campo di content. */
    private const LABELS = [
        'filters' => 'filtersLabel', 'apply' => 'applyLabel', 'more' => 'moreLabel', 'sort' => 'sortLabel',
        'close' => 'closeLabel', 'results' => 'resultsLabel', 'all' => 'allLabel', 'upcoming' => 'upcomingLabel',
        'past' => 'pastLabel', 'month' => 'monthLabel', 'from' => 'fromLabel', 'to' => 'toLabel',
        'grid' => 'gridLabel', 'rows' => 'rowsLabel',
    ];

    public function render(Node $node, RenderContext $ctx): string
    {
        $postType = $this->str($node->settings['postType'] ?? null, '');
        $source = $node->settings['source'] ?? null;
        if ($source === 'component') {
            $types = $this->paramTypes($this->str($node->settings['component'] ?? null, ''));
        } elseif ($source === 'terms') {
            $types = $this->fieldTypes('', $this->str($node->settings['taxonomy'] ?? null, ''));
        } else {
            $types = $this->fieldTypes($postType);
        }
        $config = CollectionConfig::fromSettings($node->id, $node->settings, $types);

        $cardNode = $this->child($node, self::CARD);
        $card = $cardNode?->children[0] ?? null;
        $template = $card === null ? ['html' => '', 'slots' => []] : $ctx->template($card);

        $emptyNode = $this->child($node, self::EMPTY);
        $empty = $emptyNode === null ? '' : $ctx->renderChildren($emptyNode);

        // Solo i testi del documento: i predefiniti si traducono a ogni richiesta in CollectionView.
        $labels = [];
        foreach (self::LABELS as $key => $field) {
            $labels[$key] = $this->str($node->content[$field] ?? null, '');
        }

        $countNode = $this->child($node, self::COUNT);
        $count = null;
        $countClass = '';
        if ($countNode !== null) {
            $count = ['text' => $this->str($countNode->content['text'] ?? null, ''), 'one' => $this->str($countNode->content['one'] ?? null, '')];
            $countClass = $ctx->classes($countNode);
        }

        $resetNode = $this->child($node, self::RESET);
        $reset = $resetNode === null ? null : $this->str($resetNode->content['text'] ?? null, '');
        $resetClass = $resetNode === null ? '' : $ctx->classes($resetNode);

        $classes = preg_split('/\s+/', trim($ctx->classes($node))) ?: [];
        $class = implode(' ', array_values(array_filter(
            $classes,
            static fn (string $c): bool => $c !== '' && $c !== 'nc-collection',
        )));

        $data = [
            'node' => $node->id,
            'config' => $config->toArray(),
            'template' => $template,
            'empty' => $empty,
            'count' => $count,
            'reset' => $reset,
            'countClass' => $countClass,
            'resetClass' => $resetClass,
            'labels' => $labels,
            'class' => $class,
        ];

        $state = CollectionState::fromRequest($config, []);
        $fallback = CollectionView::render($data, $config, $state, CollectionQuery::page($config, $state, $template), 0);

        return $ctx->slot('nc/collection', $data, $fallback);
    }

    public function validateNode(array $node, string $path): array
    {
        $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
        $children = is_array($node['children'] ?? null) ? array_values($node['children']) : [];
        $errors = [];
        $source = $settings['source'] ?? 'posts';

        if ($source === 'component') {
            $name = is_string($settings['component'] ?? null) ? $settings['component'] : '';
            $dataSource = Components::instance()->dataSource($name);
            if ($dataSource === null) {
                $errors[] = $this->error(
                    "$path/settings/source",
                    __('the component source is not registered', 'nucleo'),
                    $settings['source'] ?? null,
                    __('use source posts, or a component registered by a plugin', 'nucleo'),
                    'unknown_component',
                );
            } else {
                $errors = array_merge(
                    $errors,
                    $this->paramErrors(self::items($settings['filters'] ?? null), $dataSource->schema()['params'], $path),
                );
            }
        } elseif ($source === 'terms') {
            $errors = array_merge($errors, $this->termErrors($settings, $path));
        } else {
            $postType = is_string($settings['postType'] ?? null) ? $settings['postType'] : '';
            if ($postType === '' || !CollectionQuery::postTypeAllowed($postType)) {
                $errors[] = $this->error(
                    "$path/settings/postType",
                    __('post type not registered', 'nucleo'),
                    $settings['postType'] ?? null,
                    __('use the slug of a public post type of the model', 'nucleo'),
                    'unknown_post_type',
                );
            } else {
                $fieldTypes = $this->fieldTypes($postType);
                if ($this->badOrderby($settings['orderby'] ?? null, $fieldTypes)) {
                    $errors[] = $this->error(
                        "$path/settings/orderby",
                        __('field not sortable', 'nucleo'),
                        $settings['orderby'],
                        __('sort by a number, date, text or select field of the post type', 'nucleo'),
                        'unknown_field',
                    );
                }
                $errors = array_merge(
                    $errors,
                    $this->filterErrors(self::items($settings['filters'] ?? null), $postType, $fieldTypes, $path),
                    $this->sortErrors(self::items($settings['sorts'] ?? null), $fieldTypes, $path),
                );
            }
        }

        return array_merge($errors, $this->childErrors($children, $path, $source === 'terms'));
    }

    /**
     * Sorgente terms: tassonomia ammessa, parent esistente (slug, oppure "0" o vuoto), orderby ammesso, nessun
     * filtro né ordinamento.
     * @param array<string,mixed> $settings
     * @return list<ValidationError>
     */
    private function termErrors(array $settings, string $path): array
    {
        $taxonomy = is_string($settings['taxonomy'] ?? null) ? $settings['taxonomy'] : '';
        if (!CollectionQuery::taxonomyAllowed($taxonomy)) {
            return [$this->error(
                "$path/settings/taxonomy",
                __('taxonomy not registered or not public', 'nucleo'),
                $settings['taxonomy'] ?? null,
                __('use the slug of a public taxonomy, not nav_menu or wp_*', 'nucleo'),
                'unknown_taxonomy',
            )];
        }

        $errors = [];
        $parent = $settings['parent'] ?? '';
        // Stessa regola di CollectionConfig: un valore che la configurazione scarterebbe è un errore, non "tutti".
        $badParent = !is_string($parent) || ($parent !== '' && preg_match(CollectionConfig::PARENT_RE, $parent) !== 1);
        if ($badParent || ($parent !== '' && $parent !== '0' && get_term_by('slug', $parent, $taxonomy) === false)) {
            $errors[] = $this->error(
                "$path/settings/parent",
                __('parent term not found in the taxonomy', 'nucleo'),
                $parent,
                __('use the slug of an existing term, 0 for the top level, or leave it empty for all terms', 'nucleo'),
                'unknown_term',
            );
        }

        if ($this->badOrderby($settings['orderby'] ?? null, $this->fieldTypes('', $taxonomy), 'terms')) {
            $errors[] = $this->error(
                "$path/settings/orderby",
                __('field not sortable', 'nucleo'),
                $settings['orderby'],
                __('sort by name, slug, count, or a number, date, text or select field of the taxonomy', 'nucleo'),
                'unknown_field',
            );
        }

        if (self::items($settings['filters'] ?? null) !== [] || self::items($settings['sorts'] ?? null) !== []) {
            $errors[] = $this->error(
                "$path/settings/filters",
                __('a terms collection has no filters and no sorts', 'nucleo'),
                null,
                __('remove the filters and sorts; use orderby instead', 'nucleo'),
                'terms_no_filters',
            );
        }

        return $errors;
    }

    /**
     * @param list<mixed> $filters
     * @param array<string,string> $fieldTypes
     * @return list<ValidationError>
     */
    private function filterErrors(array $filters, string $postType, array $fieldTypes, string $path): array
    {
        $errors = [];
        $keys = [];
        foreach ($filters as $i => $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $base = "$path/settings/filters/$i";
            $key = $filter['key'] ?? null;
            if (is_string($key)) {
                if ($key === 'sort' || $key === 'page' || in_array($key, $keys, true)) {
                    $errors[] = $this->error(
                        "$base/key",
                        __('filter key repeated or reserved', 'nucleo'),
                        $key,
                        __('use a unique key, different from sort and page', 'nucleo'),
                        'duplicate_key',
                    );
                }
                $keys[] = $key;
            }

            $type = $filter['type'] ?? null;
            $ui = $filter['ui'] ?? null;
            if ($type === 'taxonomy') {
                $taxonomy = $filter['taxonomy'] ?? null;
                if (!is_string($taxonomy) || !taxonomy_exists($taxonomy) || !in_array($taxonomy, get_object_taxonomies($postType), true)) {
                    $errors[] = $this->error(
                        "$base/taxonomy",
                        __('taxonomy not registered or not attached to the post type', 'nucleo'),
                        $taxonomy ?? null,
                        __('use a taxonomy attached to the post type', 'nucleo'),
                        'unknown_taxonomy',
                    );
                }
                if (!in_array($ui, self::PICK_UI, true)) {
                    $errors[] = $this->badUi("$base/ui", $ui, self::PICK_UI);
                }
            } elseif ($type === 'field') {
                $field = $filter['field'] ?? null;
                $fieldType = is_string($field) ? ($fieldTypes[$field] ?? null) : null;
                if (!in_array($fieldType, array_merge(self::PICK_TYPES, self::RANGE_TYPES), true)) {
                    $errors[] = $this->unknownField("$base/field", $field);
                } elseif (in_array($fieldType, self::PICK_TYPES, true)) {
                    if (!in_array($ui, self::PICK_UI, true)) {
                        $errors[] = $this->badUi("$base/ui", $ui, self::PICK_UI);
                    }
                } elseif ($ui !== 'range') {
                    $errors[] = $this->badUi("$base/ui", $ui, ['range']);
                }
            } elseif ($type === 'date') {
                $field = $filter['field'] ?? null;
                if ($field !== null && (!is_string($field) || ($fieldTypes[$field] ?? null) !== 'date')) {
                    $errors[] = $this->unknownField("$base/field", $filter['field']);
                }
                if (!in_array($ui, ['pills', 'select', 'range'], true)) {
                    $errors[] = $this->badUi("$base/ui", $ui, ['pills', 'select', 'range']);
                }
            } elseif ($type === 'search') {
                if ($ui !== 'text') {
                    $errors[] = $this->badUi("$base/ui", $ui, ['text']);
                }
            }
        }

        return $errors;
    }

    /**
     * Filtri param: il parametro deve esistere in schema().params della sorgente component.
     * @param list<mixed> $filters
     * @param array<string,mixed> $params
     * @return list<ValidationError>
     */
    private function paramErrors(array $filters, array $params, string $path): array
    {
        $errors = [];
        foreach ($filters as $i => $filter) {
            if (!is_array($filter) || ($filter['type'] ?? null) !== 'param') {
                continue;
            }
            $param = $filter['param'] ?? null;
            // Ui range: servono entrambe le chiavi param_min e param_max nello schema.
            $needed = ($filter['ui'] ?? null) === 'range' && is_string($param)
                ? [$param . '_min', $param . '_max']
                : [$param];
            foreach ($needed as $name) {
                if (!is_string($name) || !array_key_exists($name, $params)) {
                    $errors[] = $this->unknownField("$path/settings/filters/$i/param", $param);
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Tipi dei parametri della sorgente component (type dello schema), come fieldTypes.
     * @return array<string,string>
     */
    private function paramTypes(string $name): array
    {
        $source = Components::instance()->dataSource($name);
        if ($source === null) {
            return [];
        }
        $types = [];
        foreach ($source->schema()['params'] as $key => $def) {
            if (is_string($def['type'] ?? null)) {
                $types[(string) $key] = $def['type'];
            }
        }

        return $types;
    }

    /**
     * @param list<mixed> $sorts
     * @param array<string,string> $fieldTypes
     * @return list<ValidationError>
     */
    private function sortErrors(array $sorts, array $fieldTypes, string $path): array
    {
        $errors = [];
        $keys = [];
        foreach ($sorts as $i => $sort) {
            if (!is_array($sort)) {
                continue;
            }
            $base = "$path/settings/sorts/$i";
            $key = $sort['key'] ?? null;
            if (is_string($key)) {
                if ($key === 'sort' || $key === 'page' || in_array($key, $keys, true)) {
                    $errors[] = $this->error(
                        "$base/key",
                        __('sort key repeated or reserved', 'nucleo'),
                        $key,
                        __('use a unique key, different from sort and page', 'nucleo'),
                        'duplicate_key',
                    );
                }
                $keys[] = $key;
            }
            if ($this->badOrderby($sort['orderby'] ?? null, $fieldTypes)) {
                $errors[] = $this->unknownField("$base/orderby", $sort['orderby'] ?? null);
            }
        }

        return $errors;
    }

    /**
     * Ordinamento non ammesso. Con source terms, name, slug e count sono sempre ammessi; null vale il default.
     * @param array<string,string> $fieldTypes
     */
    private function badOrderby(mixed $orderby, array $fieldTypes, string $source = 'posts'): bool
    {
        if ($source === 'terms' && $orderby !== null) {
            if (in_array($orderby, ['name', 'slug', 'count'], true)) {
                return false;
            }
            if (!is_string($orderby) || !str_starts_with($orderby, 'field:')) {
                return true;
            }
        }
        if (!is_string($orderby) || !str_starts_with($orderby, 'field:')) {
            return false;
        }

        return !in_array($fieldTypes[substr($orderby, 6)] ?? null, self::SORT_TYPES, true);
    }

    /**
     * @param list<mixed> $children
     * @return list<ValidationError>
     */
    private function childErrors(array $children, string $path, bool $terms = false): array
    {
        $errors = [];
        $cards = [];
        foreach ($children as $i => $child) {
            if (!is_array($child)) {
                continue;
            }
            if (($child['type'] ?? null) === self::CARD) {
                $cards[] = $i;
            }
            // Le parti della collezione sono lette come dati e mai compilate: una condizione su di loro sarebbe ignorata.
            $when = $child['visibility']['when'] ?? null;
            if (in_array($child['type'] ?? null, [self::CARD, self::EMPTY, self::COUNT, self::RESET], true) && is_array($when) && $when !== []) {
                $errors[] = $this->error(
                    "$path/children/$i/visibility/when",
                    __('collection parts do not take visibility conditions: the collection is cached and shared by every visitor', 'nucleo'),
                    null,
                    __('put filled or empty on a node inside the part; put visitor conditions on the nc/collection node', 'nucleo'),
                    'card_block_not_allowed',
                );
            }
        }
        if (count($cards) !== 1) {
            $errors[] = $this->error(
                "$path/children",
                __('nc/collection needs exactly one card: nc/collection-card', 'nucleo'),
                count($cards),
                __('add one nc/collection-card child with one node inside', 'nucleo'),
                'card_missing',
            );
        }
        if ($cards === []) {
            return $errors;
        }

        $card = $children[$cards[0]];
        $cardChildren = is_array($card['children'] ?? null) ? array_values($card['children']) : [];
        $cardPath = "$path/children/{$cards[0]}/children";
        if (count($cardChildren) > 1) {
            $errors[] = $this->error(
                $cardPath,
                __('the card accepts one child', 'nucleo'),
                count($cardChildren),
                __('wrap the card nodes in one nc/container or use a ref to a section', 'nucleo'),
                'card_missing',
            );
        }
        if (is_array($cardChildren[0] ?? null)) {
            array_push($errors, ...CardRules::errors($cardChildren[0], "$cardPath/0"));
            if ($terms && self::readsOtherThanTerm($cardChildren[0])) {
                $errors[] = $this->error(
                    "$cardPath/0",
                    __('the card of a terms collection reads the term, not the post or other sources', 'nucleo'),
                    $cardChildren[0]['type'] ?? null,
                    __('the card of a term reads the term with source term', 'nucleo'),
                    'card_term_source',
                );
            }
        }

        return $errors;
    }

    /**
     * Scheda di un termine: blocchi che leggono il post, o sorgenti diverse da term, a ogni profondità.
     * @param array<mixed> $node
     */
    private static function readsOtherThanTerm(array $node): bool
    {
        foreach ($node as $key => $value) {
            if ($key === 'type' && is_string($value) && in_array($value, self::TERM_BLOCKS, true)) {
                return true;
            }
            if ($key === 'source' && is_string($value) && in_array($value, self::TERM_OTHER_SOURCES, true)) {
                return true;
            }
            if (is_array($value) && self::readsOtherThanTerm($value)) {
                return true;
            }
        }

        return false;
    }

    private function unknownField(string $path, mixed $value): ValidationError
    {
        return $this->error(
            $path,
            __('field not in the model of the post type, or type not allowed here', 'nucleo'),
            $value,
            __('use a field of the post type with a type allowed for this filter or sort', 'nucleo'),
            'unknown_field',
        );
    }

    /** @param list<string> $allowed */
    private function badUi(string $path, mixed $value, array $allowed): ValidationError
    {
        return new ValidationError(
            $path,
            __('ui not allowed for this filter type', 'nucleo'),
            $value,
            $allowed,
            __('use one of the allowed ui values', 'nucleo'),
            'bad_ui',
        );
    }

    private function error(string $path, string $message, mixed $value, string $hint, string $code): ValidationError
    {
        return new ValidationError($path, $message, $value, [], $hint, $code);
    }

    /**
     * Tipi dei campi del modello per la localizzazione: il tipo di contenuto (postType) oppure la tassonomia.
     * @return array<string,string>
     */
    private function fieldTypes(string $postType, string $taxonomy = ''): array
    {
        $types = [];
        $location = $taxonomy !== '' ? ['taxonomy', $taxonomy] : ['postType', $postType];
        $groups = ModelRepository::model()['fieldGroups'] ?? [];
        if ($location[1] === '' || !is_array($groups)) {
            return $types;
        }
        foreach ($groups as $group) {
            if (!is_array($group) || ($group['location'][$location[0]] ?? null) !== $location[1]) {
                continue;
            }
            foreach (self::items($group['fields'] ?? null) as $field) {
                if (is_array($field) && is_string($field['key'] ?? null) && is_string($field['type'] ?? null)) {
                    $types[$field['key']] = $field['type'];
                }
            }
        }

        return $types;
    }

    private function child(Node $node, string $type): ?Node
    {
        foreach ($node->children as $child) {
            if ($child->type === $type) {
                return $child;
            }
        }

        return null;
    }

    /** @return list<mixed> */
    private static function items(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private function str(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }
}
