<?php

declare(strict_types=1);

namespace Nucleo\Content;

defined('ABSPATH') || exit;

use Nucleo\Services;

/**
 * Registra in WordPress tipi, tassonomie e meta del modello salvato. Legge l'artefatto dello store.
 * Con lo store vuoto (generazione nuova dopo un reset o un import, cache cancellata) legge l'opzione dal database,
 * con una query e senza scrivere: la ricostruzione la fa StoreMaintenance fuori dal frontend.
 */
final class ModelRegistrar
{
    /** @var array{postType: array<string,object>, taxonomy: array<string,object>} oggetti registrati da questa classe nella richiesta */
    private static array $registered = ['postType' => [], 'taxonomy' => []];

    /** Chiamata da Layout\PostTypes::registerTypes() su init. Senza modello non registra nulla. */
    public static function registerAll(): void
    {
        $model = self::current();
        if ($model === null) {
            return;
        }

        foreach ($model['postTypes'] ?? [] as $postType) {
            if (!post_type_exists($postType['slug'])) {
                $object = register_post_type($postType['slug'], self::postTypeArgs($postType));
                if ($object instanceof \WP_Post_Type) {
                    self::$registered['postType'][$postType['slug']] = $object;
                }
            }
        }

        foreach ($model['taxonomies'] ?? [] as $taxonomy) {
            if (!taxonomy_exists($taxonomy['slug'])) {
                $object = register_taxonomy($taxonomy['slug'], $taxonomy['postTypes'] ?? [], self::taxonomyArgs($taxonomy));
                if ($object instanceof \WP_Taxonomy) {
                    self::$registered['taxonomy'][$taxonomy['slug']] = $object;
                }
            }
        }

        foreach ($model['fieldGroups'] ?? [] as $group) {
            $location = $group['location'];
            foreach ($group['fields'] ?? [] as $field) {
                if (isset($location['postType'])) {
                    register_post_meta($location['postType'], $field['key'], self::metaArgs($field));
                } else {
                    register_term_meta($location['taxonomy'], $field['key'], self::metaArgs($field));
                }
            }
        }
    }

    /**
     * Il tipo ('postType') o la tassonomia ('taxonomy') registrato ora è quello registrato da questa classe:
     * se altro codice l'ha tolto e registrato di nuovo, non lo è più.
     */
    public static function registered(string $kind, string $slug): bool
    {
        $current = $kind === 'postType' ? get_post_type_object($slug) : get_taxonomy($slug);

        return isset(self::$registered[$kind][$slug]) && self::$registered[$kind][$slug] === $current;
    }

    /**
     * Modello da registrare. Nessuna query se l'artefatto c'è, o se manca in uno store sano (con i token):
     * lì manca perché nessuno ha mai salvato un modello.
     *
     * @return array<string,mixed>|null
     */
    private static function current(): ?array
    {
        $store = Services::store();
        $model = $store->get(ModelRepository::ARTIFACT);
        if ($model !== null || $store->get('tokens') !== null) {
            return $model;
        }
        $stored = get_option(ModelRepository::OPTION, null);

        return is_array($stored) ? $stored : null;
    }

    /**
     * @param array<string,mixed> $type
     * @return array<string,mixed>
     */
    private static function postTypeArgs(array $type): array
    {
        $args = [
            'label' => $type['plural'],
            'labels' => ['name' => $type['plural'], 'singular_name' => $type['singular']],
            'public' => $type['public'] ?? true,
            'has_archive' => $type['hasArchive'] ?? false,
            'rewrite' => ['slug' => ($type['rewrite'] ?? '') ?: $type['slug']],
            'supports' => $type['supports'] ?? ['title'],
            'show_in_rest' => true,
        ];
        if (isset($type['icon'])) {
            $args['menu_icon'] = $type['icon'];
        }

        return $args;
    }

    /**
     * @param array<string,mixed> $taxonomy
     * @return array<string,mixed>
     */
    private static function taxonomyArgs(array $taxonomy): array
    {
        return [
            'label' => $taxonomy['plural'],
            'labels' => ['name' => $taxonomy['plural'], 'singular_name' => $taxonomy['singular']],
            'hierarchical' => $taxonomy['hierarchical'] ?? false,
            'public' => $taxonomy['public'] ?? true,
            'rewrite' => ['slug' => $taxonomy['slug']],
            'show_in_rest' => true,
        ];
    }

    /**
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    private static function metaArgs(array $field): array
    {
        $schema = self::metaSchema($field);

        return [
            'type' => $schema['type'],
            'single' => true,
            'description' => (string) $field['label'],
            'show_in_rest' => ['schema' => $schema],
        ];
    }

    /**
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    private static function metaSchema(array $field): array
    {
        $multiple = !empty($field['multiple']);

        return match ($field['type']) {
            'number' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            'image' => ['type' => 'integer'],
            'relation' => $multiple ? ['type' => 'array', 'items' => ['type' => 'integer']] : ['type' => 'integer'],
            'select' => $multiple ? ['type' => 'array', 'items' => ['type' => 'string']] : ['type' => 'string'],
            'repeater' => ['type' => 'array', 'items' => ['type' => 'object']],
            default => ['type' => 'string'],
        };
    }
}
