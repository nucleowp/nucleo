<?php

declare(strict_types=1);

namespace Nucleo\Layout;

use Nucleo\Schema\ValidationError;

/**
 * Formato delle condizioni dei layout (meta.conditions): {include, exclude?, priority?}.
 * Ogni condizione è {"type", ...chiavi del tipo}. Le chiavi della mappa sono in docs/contracts/layout-keys.md.
 */
final class LayoutConditions
{
    /** Codice stabile degli errori di formato delle condizioni. */
    public const ERROR_CODE = 'layout_condition';

    /**
     * Tipo → chiavi ammesse oltre "type". Tipo del valore: id (intero ≥ 1), string (non vuota), bool.
     * Una chiave che finisce con "?" è facoltativa.
     */
    public const TYPES = [
        'entire_site' => [],
        'front' => [],
        'blog' => [],
        'search' => [],
        '404' => [],
        'post' => ['value' => 'id'],
        'parent' => ['value' => 'id'],
        'post_type' => ['value' => 'string'],
        'archive' => ['value' => 'string'],
        'author' => ['value' => 'id'],
        'author_archive' => ['value' => 'id'],
        'term' => ['taxonomy' => 'string', 'value' => 'id', 'children?' => 'bool'],
        'term_archive' => ['taxonomy' => 'string', 'value' => 'id', 'children?' => 'bool'],
        'language' => ['value' => 'string'],
        'product_type' => ['value' => 'product_type'],
        'product_tag' => ['value' => 'id'],
        'product_attribute' => ['taxonomy' => 'attribute', 'value' => 'id'],
    ];

    /** Tipi di prodotto WooCommerce ammessi in una condizione product_type. */
    public const PRODUCT_TYPES = ['simple', 'variable', 'grouped', 'external'];

    /** Segnaposto del valore negli esempi, per tipo di valore e chiave. */
    public const PLACEHOLDERS = [
        'id' => 123,
        'string' => '<value>',
        'bool' => true,
        'product_type' => 'simple',
        'attribute' => 'pa_<slug>',
    ];

    /**
     * Errori di formato di meta.conditions. Lista vuota se il formato è valido.
     *
     * @param mixed $conditions il valore di meta.conditions
     * @param string $path JSON Pointer di meta.conditions nel documento
     * @return list<ValidationError>
     */
    public static function validate(mixed $conditions, string $path = '/meta/conditions'): array
    {
        $errors = [];
        if (!self::isObject($conditions)) {
            $errors[] = self::error($path, __('conditions must be an object with include, exclude and priority', 'nucleo'), $conditions);
            return $errors;
        }

        $allowed = ['include', 'exclude', 'priority'];
        foreach (array_keys($conditions) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $errors[] = self::error(
                    "$path/$key",
                    sprintf(/* translators: %s: the unknown key */ __('unknown key "%s" in conditions', 'nucleo'), $key),
                    null,
                    $allowed,
                );
            }
        }

        if (!array_key_exists('include', $conditions)) {
            $errors[] = self::error("$path/include", __('include is required', 'nucleo'));
        } else {
            self::validateList($conditions['include'], "$path/include", $errors);
        }

        if (array_key_exists('exclude', $conditions)) {
            self::validateList($conditions['exclude'], "$path/exclude", $errors);
        }

        if (array_key_exists('priority', $conditions) && !is_int($conditions['priority'])) {
            $errors[] = self::error("$path/priority", __('priority must be an integer', 'nucleo'), $conditions['priority']);
        }

        return $errors;
    }

    /**
     * Errori di meta.conditions di un layout o popup; lista vuota per gli altri documenti o senza conditions.
     * @param array<string,mixed> $doc
     * @return list<ValidationError>
     */
    public static function errorsOf(array $doc): array
    {
        $meta = is_array($doc['meta'] ?? null) ? $doc['meta'] : [];
        if (!in_array($doc['kind'] ?? null, ['layout', 'popup'], true) || !array_key_exists('conditions', $meta)) {
            return [];
        }

        return self::validate($meta['conditions']);
    }

    /**
     * Documento con gli ID scritti come stringa numerica ("12") convertiti in intero, nelle condizioni dei tipi con ID.
     * Il resto resta com'è: la validazione segnala gli altri errori.
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    public static function normalize(array $doc): array
    {
        $conditions = is_array($doc['meta'] ?? null) ? ($doc['meta']['conditions'] ?? null) : null;
        if (!is_array($conditions)) {
            return $doc;
        }
        foreach (['include', 'exclude'] as $list) {
            if (!is_array($conditions[$list] ?? null)) {
                continue;
            }
            foreach ($conditions[$list] as $i => $condition) {
                $keys = is_array($condition) && is_string($condition['type'] ?? null) ? (self::TYPES[$condition['type']] ?? []) : [];
                foreach ($keys as $key => $kind) {
                    $value = $condition[$key] ?? null;
                    if ($kind === 'id' && is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
                        $conditions[$list][$i][$key] = (int) $value;
                    }
                }
            }
        }
        $doc['meta']['conditions'] = $conditions;

        return $doc;
    }

    /** Esempio JSON di una condizione valida del tipo, es. {"type":"post_type","value":"<value>"}. Null se il tipo non esiste. */
    public static function example(string $type): ?string
    {
        if (!array_key_exists($type, self::TYPES)) {
            return null;
        }
        $example = ['type' => $type];
        foreach (self::TYPES[$type] as $key => $kind) {
            $example[rtrim((string) $key, '?')] = self::PLACEHOLDERS[$kind];
        }
        $json = json_encode($example, JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }

    /** @param list<ValidationError> $errors */
    private static function validateList(mixed $list, string $path, array &$errors): void
    {
        if (!self::isList($list)) {
            $errors[] = self::error($path, __('must be a list of conditions', 'nucleo'), $list);
            return;
        }
        foreach ($list as $i => $condition) {
            self::validateCondition($condition, "$path/$i", $errors);
        }
    }

    /** @param list<ValidationError> $errors */
    private static function validateCondition(mixed $condition, string $path, array &$errors): void
    {
        if (!self::isObject($condition)) {
            $errors[] = self::error($path, __('condition must be an object with a type', 'nucleo'), $condition);
            return;
        }

        $type = $condition['type'] ?? null;
        if (!is_string($type) || !array_key_exists($type, self::TYPES)) {
            $errors[] = self::error(
                "$path/type",
                array_key_exists('type', $condition) ? __('unknown condition type', 'nucleo') : __('condition type is required', 'nucleo'),
                $condition['type'] ?? null,
                array_map('strval', array_keys(self::TYPES)),
                __('use one of the allowed types; catalog-get topic layouts shows the format of each type', 'nucleo'),
            );
            return;
        }

        $fix = __('expected format: ', 'nucleo') . (self::example($type) ?? '');
        $declared = self::TYPES[$type];
        $keys = ['type'];
        foreach (array_keys($declared) as $key) {
            $keys[] = rtrim((string) $key, '?');
        }

        foreach (array_keys($condition) as $key) {
            if (!in_array((string) $key, $keys, true)) {
                $errors[] = self::error(
                    "$path/$key",
                    sprintf(/* translators: 1: the unknown key, 2: condition type */ __('unknown key "%1$s" in a %2$s condition', 'nucleo'), $key, $type),
                    null,
                    $keys,
                    $fix,
                );
            }
        }

        foreach ($declared as $key => $kind) {
            $name = rtrim((string) $key, '?');
            if (!array_key_exists($name, $condition)) {
                if ($name === (string) $key) {
                    $errors[] = self::error(
                        "$path/$name",
                        sprintf(/* translators: 1: the missing key, 2: condition type */ __('missing key "%1$s" in a %2$s condition', 'nucleo'), $name, $type),
                        null,
                        [],
                        $fix,
                    );
                }
                continue;
            }
            if (!self::matches($condition[$name], $kind)) {
                $errors[] = self::error(
                    "$path/$name",
                    sprintf(/* translators: 1: the key, 2: expected value kind */ __('key "%1$s" must be %2$s', 'nucleo'), $name, self::kindLabel($kind)),
                    $condition[$name],
                    [],
                    $fix,
                );
            }
        }
    }

    private static function matches(mixed $value, string $kind): bool
    {
        return match ($kind) {
            'id' => is_int($value) && $value >= 1,
            'string' => is_string($value) && trim($value) !== '',
            'product_type' => is_string($value) && in_array($value, self::PRODUCT_TYPES, true),
            'attribute' => is_string($value) && preg_match('/^pa_[a-z0-9_-]+$/D', $value) === 1,
            default => is_bool($value),
        };
    }

    private static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'id' => __('an integer >= 1', 'nucleo'),
            'string' => __('a string with at least one character', 'nucleo'),
            'product_type' => __('one of simple, variable, grouped, external', 'nucleo'),
            'attribute' => __('a product attribute taxonomy (pa_<slug>)', 'nucleo'),
            default => __('a boolean', 'nucleo'),
        };
    }

    /** Oggetto JSON: array associativo, o array vuoto (una lista non vuota non è un oggetto). */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /** @param list<mixed> $allowed */
    private static function error(string $path, string $message, mixed $got = null, array $allowed = [], ?string $fix = null): ValidationError
    {
        return new ValidationError($path, $message, $got, $allowed, $fix, self::ERROR_CODE);
    }
}
