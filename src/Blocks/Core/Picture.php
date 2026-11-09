<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

/** Avvolge un <img> in <picture> con le sorgenti dei formati moderni; senza sorgenti restituisce l'<img> com'è. */
final class Picture
{
    /**
     * @param list<array{type:string,srcset:string}> $sources nel formato preferito per primo
     * @param string $sizes attributo sizes dell'<img>, ripetuto su ogni source
     */
    public static function wrap(string $img, array $sources, string $sizes = ''): string
    {
        if ($sources === []) {
            return $img;
        }

        $html = '<picture>';
        foreach ($sources as $source) {
            $html .= '<source type="' . self::esc($source['type']) . '"'
                . ' srcset="' . self::esc($source['srcset']) . '"';
            if ($sizes !== '') {
                $html .= ' sizes="' . self::esc($sizes) . '"';
            }
            $html .= '>';
        }

        return $html . $img . '</picture>';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
