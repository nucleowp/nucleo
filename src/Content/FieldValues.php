<?php

declare(strict_types=1);

namespace Nucleo\Content;

/**
 * Valori dei campi dei contenuti e dei termini: normali post meta e term meta (docs/contracts/content-model.md).
 * $kind: 'post' oppure 'term'.
 */
final class FieldValues
{
    /**
     * Campi dei gruppi la cui location corrisponde al tipo del post o alla tassonomia del termine.
     *
     * @return list<array<string,mixed>>
     */
    public static function fields(string $kind, int $id): array
    {
        $location = self::location($kind, $id);

        return $location === null ? [] : self::fieldsFor($location);
    }

    /**
     * Campi dei gruppi con questa location: ['postType' => ...] oppure ['taxonomy' => ...].
     *
     * @param array{postType?:string,taxonomy?:string} $location
     * @return list<array<string,mixed>>
     */
    public static function fieldsFor(array $location): array
    {
        $fields = [];
        foreach (ModelRepository::model()['fieldGroups'] as $group) {
            if ($group['location'] !== $location) {
                continue;
            }
            foreach ($group['fields'] ?? [] as $field) {
                if (is_array($field)) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * Valori per chiave. Campo senza valore → null.
     *
     * @return array<string,mixed>
     */
    public static function get(string $kind, int $id): array
    {
        $values = [];
        foreach (self::fields($kind, $id) as $field) {
            $values[$field['key']] = self::read($field, get_metadata($kind, $id, $field['key'], true));
        }

        return $values;
    }

    /**
     * Parziale: le chiavi assenti restano. Valida tutto prima di scrivere; con un errore non scrive nulla.
     *
     * @param array<string,mixed> $values
     * @return array{ok:bool, errors?:list<array{code:string,path:string,message:string}>}
     */
    public static function set(string $kind, int $id, array $values): array
    {
        $checked = self::plan(self::fields($kind, $id), $values);
        if ($checked['errors'] !== []) {
            return ['ok' => false, 'errors' => $checked['errors']];
        }

        foreach ($checked['plan'] as $key => $value) {
            if ($value === null) {
                delete_metadata($kind, $id, $key);
            } else {
                update_metadata($kind, $id, $key, self::stored($value));
            }
        }

        return ['ok' => true];
    }

    /**
     * Stessi controlli di set, senza scrivere.
     *
     * @param array{postType?:string,taxonomy?:string} $location
     * @param array<string,mixed> $values
     * @return list<array{code:string,path:string,message:string}>
     */
    public static function validate(array $location, array $values): array
    {
        return self::plan(self::fieldsFor($location), $values)['errors'];
    }

    /**
     * Valori puliti e errori per un insieme di campi. Senza errori il piano è completo.
     *
     * @param list<array<string,mixed>> $fields
     * @param array<string,mixed> $values
     * @return array{plan:array<string,mixed>, errors:list<array{code:string,path:string,message:string}>}
     */
    private static function plan(array $fields, array $values): array
    {
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field['key']] = $field;
        }

        $errors = [];
        $plan = [];
        foreach ($values as $key => $input) {
            $key = (string) $key;
            if (!isset($byKey[$key])) {
                $errors[] = new ModelError('unknown_field', '/' . $key, sprintf(/* translators: %s: field key */ __('Unknown field: %s. Only fields of the Nucleo content model are writable (bind-fields: writable true). Fields of other plugins are edited in those plugins.', 'nucleo'), $key));
                continue;
            }
            $plan[$key] = self::normalize($byKey[$key], $input, '/' . $key, $errors);
        }

        return [
            'plan' => $plan,
            'errors' => array_map(static fn (ModelError $error): array => $error->toArray(), $errors),
        ];
    }

    /** @return array{postType:string}|array{taxonomy:string}|null */
    private static function location(string $kind, int $id): ?array
    {
        if ($kind === 'post') {
            $post = get_post($id);

            return $post instanceof \WP_Post ? ['postType' => $post->post_type] : null;
        }

        $term = get_term($id);

        return $term instanceof \WP_Term ? ['taxonomy' => $term->taxonomy] : null;
    }

    /**
     * Valore dal meta grezzo: il meta assente arriva come stringa vuota.
     *
     * @param array<string,mixed> $field
     */
    private static function read(array $field, mixed $raw): mixed
    {
        if ($raw === '' || $raw === null) {
            return null;
        }

        $many = !empty($field['multiple']);

        return match ($field['type']) {
            'number' => is_numeric($raw) ? $raw + 0 : null,
            'boolean' => (bool) $raw,
            'image' => (int) $raw,
            'relation' => $many ? array_map('intval', (array) $raw) : (int) $raw,
            'select' => $many ? array_map('strval', (array) $raw) : (string) $raw,
            'repeater' => (array) $raw,
            default => (string) $raw,
        };
    }

    /**
     * Valore pulito di un campo, o null se vuoto. Gli errori si aggiungono a $errors: se ne compare uno, il valore non si usa.
     *
     * @param array<string,mixed> $field
     * @param list<ModelError> $errors
     */
    private static function normalize(array $field, mixed $input, string $path, array &$errors): mixed
    {
        if ($input === null || $input === [] || (is_string($input) && trim($input) === '')) {
            return self::required($field, $path, $errors);
        }

        $count = count($errors);
        $value = self::value($field, $input, $path, $errors);
        if (count($errors) > $count) {
            return null;
        }

        if ($value === null || $value === '') {
            return self::required($field, $path, $errors);
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $field
     * @param list<ModelError> $errors
     */
    private static function value(array $field, mixed $input, string $path, array &$errors): mixed
    {
        if ($field['type'] === 'repeater') {
            return self::repeater($field, $input, $path, $errors);
        }

        if (!empty($field['multiple']) && in_array($field['type'], ['select', 'relation'], true)) {
            return self::many($field, $input, $path, $errors);
        }

        return self::single($field, $input, $path, $errors);
    }

    /**
     * @param array<string,mixed> $field
     * @param list<ModelError> $errors
     */
    private static function single(array $field, mixed $input, string $path, array &$errors): mixed
    {
        $value = match ($field['type']) {
            'text' => is_string($input) ? sanitize_text_field($input) : null,
            'textarea' => is_string($input) ? sanitize_textarea_field($input) : null,
            'url' => is_string($input) ? self::url($input) : null,
            'email' => is_string($input) && is_email(trim($input)) ? trim($input) : null,
            'date' => is_string($input) && self::isDate($input) ? $input : null,
            'number' => self::number($field, $input),
            'boolean' => self::boolean($input),
            'image' => self::image($input),
            'relation' => self::relation($field, $input),
            'select' => self::option($field, $input),
            default => null,
        };

        return $value ?? self::invalid($path, $errors);
    }

    /**
     * @param array<string,mixed> $field
     * @param list<ModelError> $errors
     */
    private static function many(array $field, mixed $input, string $path, array &$errors): mixed
    {
        if (!is_array($input) || !array_is_list($input)) {
            return self::invalid($path, $errors);
        }

        $items = [];
        foreach ($input as $item) {
            $items[] = self::single($field, $item, $path, $errors);
        }

        return array_values(array_unique($items));
    }

    /**
     * @param array<string,mixed> $field
     * @param list<ModelError> $errors
     */
    private static function repeater(array $field, mixed $input, string $path, array &$errors): mixed
    {
        if (!is_array($input) || !array_is_list($input)) {
            return self::invalid($path, $errors);
        }

        $count = count($input);
        if (isset($field['max']) && $count > $field['max']) {
            $errors[] = new ModelError('too_many', $path, sprintf(/* translators: %d: maximum number of rows */ __('at most %d rows', 'nucleo'), $field['max']));
        }
        if (isset($field['min']) && $count < $field['min']) {
            $errors[] = new ModelError('too_few', $path, sprintf(/* translators: %d: minimum number of rows */ __('at least %d rows', 'nucleo'), $field['min']));
        }

        $subs = [];
        foreach ($field['fields'] ?? [] as $sub) {
            $subs[$sub['key']] = $sub;
        }

        $rows = [];
        foreach ($input as $i => $row) {
            if (!is_array($row)) {
                self::invalid("$path/$i", $errors);
                continue;
            }

            $cells = [];
            foreach ($row as $key => $cell) {
                if (!isset($subs[$key])) {
                    $errors[] = new ModelError('unknown_field', "$path/$i/$key", sprintf(/* translators: %s: field key */ __('Unknown field: %s.', 'nucleo'), (string) $key));
                    continue;
                }
                $value = self::normalize($subs[$key], $cell, "$path/$i/$key", $errors);
                if ($value !== null) {
                    $cells[$key] = $value;
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * @param array<string,mixed> $field
     */
    private static function number(array $field, mixed $input): int|float|null
    {
        if (is_int($input) || is_float($input)) {
            $number = $input;
        } elseif (is_string($input) && is_numeric(trim($input))) {
            $number = trim($input) + 0;
        } else {
            return null;
        }

        if (!is_finite((float) $number)) {
            return null;
        }
        if (isset($field['min']) && $number < $field['min']) {
            return null;
        }
        if (isset($field['max']) && $number > $field['max']) {
            return null;
        }

        return $number;
    }

    private static function boolean(mixed $input): ?bool
    {
        return match ($input) {
            true, 1, '1' => true,
            false, 0, '0' => false,
            default => null,
        };
    }

    private static function url(string $url): ?string
    {
        $url = esc_url_raw(trim($url));

        return in_array(wp_parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    }

    private static function image(mixed $input): ?int
    {
        $id = self::id($input);

        return $id !== null && wp_attachment_is_image($id) ? $id : null;
    }

    /** @param array<string,mixed> $field */
    private static function relation(array $field, mixed $input): ?int
    {
        $id = self::id($input);
        if ($id === null) {
            return null;
        }

        $post = get_post($id);
        $types = $field['postTypes'] ?? [];

        return $post instanceof \WP_Post && ($types === [] || in_array($post->post_type, $types, true)) ? $id : null;
    }

    /** @param array<string,mixed> $field */
    private static function option(array $field, mixed $input): ?string
    {
        if (!is_string($input) && !is_int($input)) {
            return null;
        }

        $value = (string) $input;
        foreach ($field['options'] ?? [] as $option) {
            if ((string) $option['value'] === $value) {
                return $value;
            }
        }

        return null;
    }

    private static function id(mixed $input): ?int
    {
        $id = is_int($input) ? $input : (is_string($input) && ctype_digit($input) ? (int) $input : 0);

        return $id > 0 ? $id : null;
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** Il bool si salva come '1' o '0': false scritto così com'è diventerebbe '' (meta vuoto). */
    private static function stored(mixed $value): mixed
    {
        return is_bool($value) ? ($value ? '1' : '0') : $value;
    }

    /**
     * @param array<string,mixed> $field
     * @param list<ModelError> $errors
     */
    private static function required(array $field, string $path, array &$errors): mixed
    {
        if (!empty($field['required'])) {
            $errors[] = new ModelError('required', $path, __('This field is required.', 'nucleo'));
        }

        return null;
    }

    /** @param list<ModelError> $errors */
    private static function invalid(string $path, array &$errors): mixed
    {
        $errors[] = new ModelError('invalid_value', $path, __('Invalid value.', 'nucleo'));

        return null;
    }
}
