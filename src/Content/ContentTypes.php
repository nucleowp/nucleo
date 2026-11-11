<?php

declare(strict_types=1);

namespace Nucleo\Content;

defined('ABSPATH') || exit;

use WP_Post_Type;
use WP_Taxonomy;

/**
 * Tipi di contenuto e tassonomie che le ability content-* e terms-* possono leggere e scrivere.
 * Ammessi: registrati e mostrabili (show_ui). Esclusi: tipi interni di WordPress e di Nucleo, allegati e WooCommerce.
 */
final class ContentTypes
{
    /** Tipi interni di WordPress senza prefisso wp_. */
    private const CORE_TYPES = ['revision', 'attachment', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request'];

    /** Prodotti, ordini e coupon di WooCommerce: fuori per ora. */
    private const WOO_TYPES = ['product', 'product_variation'];

    private const CORE_TAXONOMIES = ['nav_menu', 'link_category', 'post_format'];

    public static function allowsType(string $postType): bool
    {
        $object = get_post_type_object($postType);
        if (!$object instanceof WP_Post_Type || !$object->show_ui) {
            return false;
        }

        return !in_array($postType, self::CORE_TYPES, true)
            && !in_array($postType, self::WOO_TYPES, true)
            && !self::reservedPrefix($postType)
            && !str_starts_with($postType, 'shop_');
    }

    /** Tipi elencabili da nucleo/content-list: quelli ammessi più i prodotti di WooCommerce, in sola lettura. */
    public static function allowsListType(string $postType): bool
    {
        return self::allowsType($postType) || ($postType === 'product' && post_type_exists('product'));
    }

    public static function allowsTaxonomy(string $taxonomy): bool
    {
        $object = get_taxonomy($taxonomy);
        if (!$object instanceof WP_Taxonomy || !$object->show_ui || in_array($taxonomy, self::CORE_TAXONOMIES, true) || self::reservedPrefix($taxonomy)) {
            return false;
        }
        // Tassonomie di WooCommerce: categorie, tag, attributi e quelle interne dei prodotti.
        if (str_starts_with($taxonomy, 'product_') || str_starts_with($taxonomy, 'pa_')) {
            return false;
        }

        return array_filter((array) $object->object_type, self::allowsType(...)) !== [];
    }

    /** @return list<string> tipi ammessi, in ordine di registrazione */
    public static function types(): array
    {
        return array_values(array_filter(array_keys(get_post_types()), self::allowsType(...)));
    }

    /** @return list<string> tassonomie ammesse, in ordine di registrazione */
    public static function taxonomies(): array
    {
        return array_values(array_filter(array_keys(get_taxonomies()), self::allowsTaxonomy(...)));
    }

    /** Il tipo o la tassonomia compare nel modello dei contenuti, come definizione o come location di un gruppo di campi. */
    public static function modeled(string $kind, string $slug): bool
    {
        $model = ModelRepository::model();
        $own = $kind === 'postType' ? $model['postTypes'] : $model['taxonomies'];
        if (in_array($slug, array_column($own, 'slug'), true)) {
            return true;
        }
        foreach ($model['fieldGroups'] as $group) {
            if (($group['location'][$kind] ?? null) === $slug) {
                return true;
            }
        }

        return false;
    }

    /** Origini di un tipo o di una tassonomia, per site-describe, model-get, content-list e terms-get. */
    public const ORIGINS = ['nucleo-model', self::BUILTIN, 'plugin-or-theme', 'conflict'];

    /** Origine dei tipi e delle tassonomie di WordPress. */
    public const BUILTIN = 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- valore di enum, non testo.

    /**
     * Chi registra il tipo ('postType') o la tassonomia ('taxonomy'): il modello Nucleo, WordPress o altro codice
     * (un plugin o il tema). WordPress non dice quale plugin: per saperlo, cerca lo slug nel codice dei plugin attivi e del tema.
     * conflict: il modello dichiara lo slug, ma altro codice l'ha registrato prima e ModelRegistrar l'ha saltato.
     */
    public static function origin(string $kind, string $slug): string
    {
        $object = $kind === 'postType' ? get_post_type_object($slug) : get_taxonomy($slug);
        $builtin = ($object instanceof WP_Post_Type || $object instanceof WP_Taxonomy) && $object->_builtin;
        $model = ModelRepository::model();
        $own = $kind === 'postType' ? $model['postTypes'] : $model['taxonomies'];

        $declared = in_array($slug, array_column($own, 'slug'), true);
        $exists = $object instanceof WP_Post_Type || $object instanceof WP_Taxonomy;

        return match (true) {
            ModelRegistrar::registered($kind, $slug) => 'nucleo-model',
            $declared && $exists => 'conflict',
            $declared => 'nucleo-model',
            $builtin => self::BUILTIN,
            default => 'plugin-or-theme',
        };
    }

    /**
     * Tipi e tassonomie ammessi, con origine e presenza nel modello. Per site-describe e model-get.
     * Con $editable solo quelli che l'utente corrente può modificare (edit_posts del tipo, manage_terms della tassonomia).
     *
     * @return array{postTypes: list<array{slug:string,label:string,modeled:bool,registeredBy:string}>, taxonomies: list<array{slug:string,label:string,modeled:bool,registeredBy:string}>}
     */
    public static function inventory(bool $editable = false): array
    {
        $out = ['postTypes' => [], 'taxonomies' => []];
        foreach (self::types() as $slug) {
            $type = get_post_type_object($slug);
            if (!$type instanceof WP_Post_Type || ($editable && !current_user_can($type->cap->edit_posts))) {
                continue;
            }
            $out['postTypes'][] = self::entry('postType', $slug, (string) $type->labels->name);
        }
        foreach (self::taxonomies() as $slug) {
            $taxonomy = get_taxonomy($slug);
            if (!$taxonomy instanceof WP_Taxonomy || ($editable && !current_user_can($taxonomy->cap->manage_terms))) {
                continue;
            }
            $out['taxonomies'][] = self::entry('taxonomy', $slug, (string) $taxonomy->labels->name);
        }

        return $out;
    }

    /** @return array{slug:string,label:string,modeled:bool,registeredBy:string} */
    private static function entry(string $kind, string $slug, string $label): array
    {
        return ['slug' => $slug, 'label' => $label, 'modeled' => self::modeled($kind, $slug), 'registeredBy' => self::origin($kind, $slug)];
    }

    /** Opzioni che puntano a pagine di sistema: home, articoli, privacy e pagine di WooCommerce. */
    private const PROTECTED_OPTIONS = ['page_on_front', 'page_for_posts', 'wp_page_for_privacy_policy', 'woocommerce_shop_page_id',
        'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id', 'woocommerce_terms_page_id'];

    /** @return list<int> ID delle pagine di sistema: le ability non le cestinano */
    public static function protectedIds(): array
    {
        $ids = [];
        foreach (self::PROTECTED_OPTIONS as $option) {
            $id = (int) get_option($option, 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function reservedPrefix(string $slug): bool
    {
        return str_starts_with($slug, 'nucleo_') || str_starts_with($slug, 'wp_');
    }
}
