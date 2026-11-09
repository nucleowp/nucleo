<?php

declare(strict_types=1);

namespace Nucleo\Runtime\Collection;

/** Versione dei contenuti (opzione nucleo_content_version, autoload): cresce a ogni modifica di post, meta o termini. */
final class ContentVersion
{
    public const OPTION = 'nucleo_content_version';

    /** Aggancia gli hook di modifica. Idempotente. */
    public static function register(): void
    {
        foreach (['save_post', 'deleted_post', 'set_object_terms'] as $hook) {
            if (has_action($hook, [self::class, 'onPost']) === false) {
                add_action($hook, [self::class, 'onPost']);
            }
        }
        foreach (['edited_term', 'created_term', 'delete_term'] as $hook) {
            if (has_action($hook, [self::class, 'bump']) === false) {
                add_action($hook, [self::class, 'bump']);
            }
        }
        foreach (['added_term_meta', 'updated_term_meta', 'deleted_term_meta'] as $hook) {
            if (has_action($hook, [self::class, 'onTermMeta']) === false) {
                add_action($hook, [self::class, 'onTermMeta'], 10, 3);
            }
        }
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            if (has_action($hook, [self::class, 'onMeta']) === false) {
                add_action($hook, [self::class, 'onMeta'], 10, 3);
            }
        }
    }

    public static function get(): int
    {
        return (int) get_option(self::OPTION, 0);
    }

    public static function bump(): void
    {
        update_option(self::OPTION, self::get() + 1, true);
    }

    /** Solo le meta dei post contano: chiavi senza "_", più _thumbnail_id. */
    public static function onMeta(mixed $metaIds, mixed $objectId, mixed $metaKey): void
    {
        if (!is_string($metaKey) || ($metaKey !== '_thumbnail_id' && str_starts_with($metaKey, '_'))) {
            return;
        }
        self::onPost($objectId);
    }

    /**
     * Term meta: solo i campi del modello su una tassonomia visibile. Le meta di servizio (per esempio i contatori
     * che WooCommerce riscrive di continuo) non invalidano le collezioni.
     */
    public static function onTermMeta(mixed $metaIds, mixed $termId, mixed $metaKey): void
    {
        $term = is_numeric($termId) ? get_term((int) $termId) : null;
        if (!is_string($metaKey) || !$term instanceof \WP_Term || !is_taxonomy_viewable($term->taxonomy)) {
            return;
        }
        foreach (\Nucleo\Content\FieldValues::fieldsFor(['taxonomy' => $term->taxonomy]) as $field) {
            if (($field['key'] ?? null) === $metaKey) {
                self::bump();

                return;
            }
        }
    }

    /**
     * Solo i contenuti che una collezione può mostrare: tipi pubblici, niente revisioni né salvataggi automatici.
     * Gli invii dei moduli (nucleo_submission), oembed_cache e i tipi privati non svuotano le cache.
     */
    public static function onPost(mixed $postId): void
    {
        $post = get_post(is_numeric($postId) ? (int) $postId : 0);
        if (!$post instanceof \WP_Post || wp_is_post_revision($post) !== false || wp_is_post_autosave($post) !== false) {
            return;
        }
        if (!is_post_type_viewable($post->post_type)) {
            return;
        }
        self::bump();
    }
}
