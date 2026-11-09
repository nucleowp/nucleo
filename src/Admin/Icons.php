<?php

declare(strict_types=1);

namespace Nucleo\Admin;

/**
 * Icone dell'area Nucleo: sprite SVG condiviso (assets/admin/icons.svg) e tessera delle pagine.
 */
final class Icons
{
    private static ?string $sprite = null;

    private static ?string $blocks = null;

    /** Contenuto dello sprite SVG, letto una volta sola per richiesta. */
    public static function sprite(): string
    {
        if (self::$sprite === null) {
            $raw = file_get_contents(dirname(__DIR__, 2) . '/assets/admin/icons.svg');
            self::$sprite = is_string($raw) ? $raw : '';
        }

        return self::$sprite;
    }

    /** Sprite delle icone dei blocchi (assets/admin/block-icons.svg, simboli nb-*): usa i gradienti di sprite(). */
    public static function blockSprite(): string
    {
        if (self::$blocks === null) {
            $raw = file_get_contents(dirname(__DIR__, 2) . '/assets/admin/block-icons.svg');
            self::$blocks = is_string($raw) ? $raw : '';
        }

        return self::$blocks;
    }

    /**
     * Tessera con l'icona di una pagina. Stringa vuota se il nome non è nc-xxx o non è un symbol dello sprite.
     */
    public static function tile(string $name): string
    {
        if (!preg_match('/^nc-[a-z]+$/', $name) || !str_contains(self::sprite(), '<symbol id="' . $name . '"')) {
            return '';
        }

        return '<span class="nc-page__icon" aria-hidden="true"><svg width="32" height="32" focusable="false"><use href="#' . $name . '"/></svg></span>';
    }
}
