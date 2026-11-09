<?php

declare(strict_types=1);

namespace Nucleo\Docs;

/** Topic "model" di nucleo/catalog-get: formato e regole di model-set (docs/contracts/content-model.md). */
final class ModelTopic
{
    /** @return array{topic:string,summary:string,rules:list<string>,data:array<string,mixed>} */
    public static function topic(): array
    {
        return [
            'topic' => 'model',
            'summary' => 'Content model for model-set and model-validate: post types, taxonomies, field groups.',
            'rules' => [
                'Document: {"schema": 1, "postTypes": [...], "taxonomies": [...], "fieldGroups": [...]}. Other keys are refused.',
                'Post type: {"slug", "singular", "plural", "public"?, "hasArchive"?, "rewrite"?, "supports"?, "icon"?}. singular and plural are the labels (Prodotto, Prodotti). supports defaults to ["title"]; add "thumbnail", "excerpt", "editor", "page-attributes" as needed. rewrite = URL base, default the slug.',
                'Taxonomy: {"slug", "singular", "plural", "postTypes"?, "hierarchical"?, "public"?}. postTypes lists the post types it applies to.',
                'Field group: {"id", "title", "location": {"postType": "<slug>"} or {"taxonomy": "<slug>"}, "fields": [...]}. The location can be a type of the model, post, page, or a type registered by another plugin or the theme.',
                'Field: {"key", "type", "label", "required"?, "help"?} plus the options of the type (data.fieldTypes). key matches ^[a-z][a-z0-9_]{0,31}$ and is the meta key. Keys are unique per location, across groups.',
                'Partial save: postTypes, taxonomies and fieldGroups you do not send keep their saved value ("kept" in the answer). A key you send replaces the whole list: to add a type, send all the types. [] empties the list.',
                'Slugs: ^[a-z][a-z0-9_-]*$, post types max 20 characters, taxonomies max 32. reserved_slug = a WordPress slug (data.reserved) or prefix wp_, or a slug registered by another plugin or the theme. model-get "unmodeled" and site-describe "content" show who registers each type (registeredBy). A slug already in the saved model is never reserved.',
                'Destructive changes (removing a type with items, a taxonomy with terms, a field with values, changing a field type with values) need their ids in confirm, for example "postType:evento", "field:dettagli/prezzo". Renaming a slug is a removal plus an addition. Field values stay in the database.',
                'Writing values: content-upsert and terms-set write only fields of the model (bind-fields: writable true). Repeater rows are arrays of objects; relations are "<post-type>:<slug>" (page:chi-siamo); images are "media:<file>" or an attachment id.',
                'After a database reset or import the Nucleo cache starts empty: site-describe reports cache.empty and cache-rebuild republishes tokens, layouts and the model. With a persistent object cache (Redis, Memcached) run wp cache flush after a database reset, or the old cache generation stays in use.',
            ],
            'data' => [
                'fieldTypes' => [
                    'text' => [],
                    'textarea' => [],
                    'number' => ['min', 'max', 'step'],
                    'url' => [],
                    'email' => [],
                    'image' => [],
                    'date' => ['format Y-m-d'],
                    'select' => ['options: [{value, label}]', 'multiple'],
                    'boolean' => [],
                    'relation' => ['postTypes', 'multiple'],
                    'repeater' => ['fields (simple types only)', 'min', 'max'],
                ],
                'reserved' => ['post', 'page', 'attachment', 'revision', 'nav_menu_item', 'action', 'author', 'order', 'theme', 'category', 'post_tag', 'link_category', 'post_format'],
                'errors' => ['schema', 'reserved_slug', 'slug_length', 'duplicate', 'unknown_target', 'nested_repeater', 'destructive'],
                'example' => [
                    'schema' => 1,
                    'postTypes' => [['slug' => 'prodotto', 'singular' => 'Prodotto', 'plural' => 'Prodotti', 'hasArchive' => true, 'supports' => ['title', 'excerpt', 'thumbnail']]],
                    'taxonomies' => [['slug' => 'linea-prodotto', 'singular' => 'Linea', 'plural' => 'Linee', 'postTypes' => ['prodotto'], 'hierarchical' => true]],
                    'fieldGroups' => [['id' => 'scheda', 'title' => 'Scheda', 'location' => ['postType' => 'prodotto'], 'fields' => [
                        ['key' => 'beneficio', 'type' => 'text', 'label' => 'Beneficio'],
                        ['key' => 'formato', 'type' => 'select', 'label' => 'Formato', 'options' => [['value' => 'capsule', 'label' => 'Capsule']]],
                    ]]],
                ],
            ],
        ];
    }
}
