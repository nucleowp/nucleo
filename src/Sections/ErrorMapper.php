<?php

declare(strict_types=1);

namespace Nucleo\Sections;

defined('ABSPATH') || exit;

use Nucleo\Schema\DocumentValidator;
use Nucleo\Schema\ValidationError;

/**
 * Riporta gli errori del documento espanso sul nodo nc/section-ref del documento di partenza che ha prodotto il nodo.
 */
final class ErrorMapper
{
    /**
     * @param array<string,mixed> $doc documento espanso
     * @param list<ValidationError> $errors errori con path nel documento espanso
     * @return list<ValidationError>
     */
    public static function remap(array $doc, Expansion $e, array $errors): array
    {
        $out = [];
        $seen = [];
        foreach ($errors as $error) {
            $mapped = self::mapOne($doc, $e, $error);
            $key = $mapped->path . "\0" . $mapped->message;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $mapped;
        }

        return $out;
    }

    /** @param array<string,mixed> $doc */
    private static function mapOne(array $doc, Expansion $e, ValidationError $error): ValidationError
    {
        $hit = self::deepest($doc, $error->path);
        if ($hit === null) {
            return $error;
        }

        $id = $hit['id'];
        if (!isset($e->origins[$id]) || $e->origins[$id] === $id) {
            return $error;
        }

        $refPath = self::pathOf($doc['root'] ?? null, '/root', (string) $e->origins[$id]);
        if ($refPath === null) {
            return $error;
        }

        $key = implode('.', array_slice($hit['rest'], 0, 2));
        $name = $e->bound[$id][$key] ?? null;
        if (is_string($name)) {
            return new ValidationError($refPath . '/settings/params/' . $name, $error->message, $error->got, $error->allowed, $error->fix, $error->code);
        }

        // Gli id dentro la sezione cambiano per ogni istanza: un fix che li cita non vale nel documento che la inserisce.
        $styleFix = $error->code === DocumentValidator::STYLE_GROUP_CODE;

        return new ValidationError(
            $refPath . '/settings/section',
            __('inside section: ', 'nucleo') . $error->message,
            $error->got,
            $error->allowed,
            $styleFix ? __('fix the style key in the section document', 'nucleo') : $error->fix,
            $error->code,
        );
    }

    /**
     * Nodo più profondo lungo il path (con id) e segmenti che seguono il suo path.
     *
     * @param array<string,mixed> $doc
     * @return array{id:string,rest:list<string>}|null
     */
    private static function deepest(array $doc, string $path): ?array
    {
        $segs = explode('/', trim($path, '/'));
        if ($segs[0] !== 'root') {
            return null;
        }

        $best = null;
        $node = $doc['root'] ?? null;
        $i = 1;
        while (is_array($node)) {
            if (is_string($node['id'] ?? null)) {
                $best = ['id' => $node['id'], 'rest' => array_slice($segs, $i)];
            }
            $idx = $segs[$i + 1] ?? null;
            $children = $node['children'] ?? null;
            if (($segs[$i] ?? null) !== 'children' || !is_string($idx) || !is_array($children) || !array_key_exists($idx, $children)) {
                break;
            }
            $node = $children[$idx];
            $i += 2;
        }

        return $best;
    }

    /** Path JSON del nodo con l'id dato, o null se non c'è. */
    private static function pathOf(mixed $node, string $path, string $id): ?string
    {
        if (!is_array($node)) {
            return null;
        }
        if (($node['id'] ?? null) === $id) {
            return $path;
        }
        $children = $node['children'] ?? null;
        if (!is_array($children)) {
            return null;
        }
        foreach ($children as $i => $child) {
            $found = self::pathOf($child, $path . '/children/' . $i, $id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
