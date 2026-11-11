<?php

declare(strict_types=1);

namespace Nucleo\Bindings;

defined('ABSPATH') || exit;

use Nucleo\Services;

/**
 * Ricompila la pagina quando cambia o si cancella una meta che un suo bind legge (metabox, ACF, meta del post).
 * Le meta interne a Nucleo (prefisso "_nucleo") si ignorano. L'immagine in evidenza (_thumbnail_id) vale come bind:post:image.
 */
final class MetaWatcher
{
    public static function register(): void
    {
        add_action('added_post_meta', [self::class, 'onMetaChanged'], 10, 4);
        add_action('updated_post_meta', [self::class, 'onMetaChanged'], 10, 4);
        add_action('deleted_post_meta', [self::class, 'onMetaChanged'], 10, 4);
    }

    /** deleted_post_meta passa la lista degli id di meta, added e updated un id: per questo il tipo è mixed. */
    public static function onMetaChanged(mixed $metaId, int $postId, string $metaKey, mixed $metaValue): void
    {
        // Id 0 (meta di WooCommerce all'attivazione): get_post_meta() restituirebbe false.
        if ($postId <= 0 || str_starts_with($metaKey, '_nucleo') || !self::readsField($postId, $metaKey)) {
            return;
        }

        Services::documents()->recompile($postId);
    }

    /** Vero se una dipendenza del post legge il campo come metabox, ACF o meta del post. */
    private static function readsField(int $postId, string $metaKey): bool
    {
        foreach (get_post_meta($postId, '_nucleo_dep', false) as $dep) {
            if (!is_string($dep)) {
                continue;
            }
            if (str_ends_with($dep, ':' . $metaKey) || ($metaKey === '_thumbnail_id' && $dep === 'bind:post:image')) {
                return true;
            }
        }

        return false;
    }
}
