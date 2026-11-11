<?php

declare(strict_types=1);

namespace Nucleo\Runtime;

defined('ABSPATH') || exit;

/**
 * Chiave del template e dell'oggetto della richiesta corrente, per il lookup delle regole asset.
 */
final class TemplateKey
{
    /** Tipo di template, per esempio 'home', 'page', 'single', 'product', 'archive'; null se non riconosciuto. */
    public static function current(): ?string
    {
        if (is_404()) {
            return '404';
        }
        if (is_search()) {
            return 'search';
        }
        if (is_front_page()) {
            return 'home';
        }


        if (is_singular()) {
            return match ((string) get_post_type()) {
                'page' => 'page',
                'post' => 'single',
                'product' => 'product',
                default => 'single-' . (string) get_post_type(),
            };
        }
        if (is_archive()) {
            return 'archive';
        }

        return null;
    }

    /** Oggetto singolo nel formato 'tipo:ID' (per esempio 'page:12'); null fuori dai singolari. */
    public static function objectKey(): ?string
    {
        if (!is_singular()) {
            return null;
        }
        $id = (int) get_queried_object_id();
        $type = get_post_type($id);
        if ($id <= 0 || !is_string($type) || $type === '') {
            return null;
        }

        return $type . ':' . $id;
    }
}
