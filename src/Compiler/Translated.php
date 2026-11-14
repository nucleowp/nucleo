<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

defined('ABSPATH') || exit;

/**
 * ID tradotti nella lingua della compilazione in corso. Senza plugin multilingua, o fuori da una compilazione, l'ID resta com'è.
 */
final class Translated
{
    /** ID dell'oggetto nella lingua della compilazione. $type: tipo di post, tassonomia o 'nav_menu'. */
    public static function id(int $id, string $type): int
    {
        $mapped = apply_filters('nucleo/translated_id', $id, $type);

        return is_int($mapped) && $mapped > 0 ? $mapped : $id;
    }

    /**
     * Originale di un contenuto tradotto: id dell'originale e lingua della traduzione. Null per un originale,
     * o senza plugin multilingua.
     * @return array{id:int,lang:string}|null
     */
    public static function source(int $postId): ?array
    {
        $source = $postId > 0 ? apply_filters('nucleo/translation_source', null, $postId) : null;
        if (!is_array($source) || !is_int($source['id'] ?? null) || !is_string($source['lang'] ?? null)) {
            return null;
        }

        return $source['id'] > 0 && $source['id'] !== $postId ? ['id' => $source['id'], 'lang' => $source['lang']] : null;
    }

    /**
     * ID dell'oggetto in tutte le lingue, incluso $id. Senza plugin multilingua: [$id].
     * $type: tipo di post, tassonomia o 'nav_menu'.
     * @return list<int>
     */
    public static function siblings(int $id, string $type): array
    {
        $list = $id > 0 ? apply_filters('nucleo/translation_siblings', [$id], $id, $type) : [];
        $ids = array_filter(is_array($list) ? $list : [], static fn (mixed $v): bool => is_int($v) && $v > 0);

        return array_values(array_unique([$id, ...$ids]));
    }

    /** Lingua della compilazione in corso (slug), o null. */
    public static function language(): ?string
    {
        $lang = apply_filters('nucleo/compile_language', null);

        return is_string($lang) && $lang !== '' ? $lang : null;
    }
}
