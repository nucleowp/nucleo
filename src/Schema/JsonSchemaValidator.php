<?php

declare(strict_types=1);

namespace Nucleo\Schema;

/**
 * Validatore JSON Schema minimo (sottoinsieme di draft 2020-12), senza dipendenze.
 * I dati arrivano da json_decode($json, true): una lista è un array JSON, un array
 * associativo è un oggetto, e [] vale sia come array sia come oggetto.
 * $ref accetta solo riferimenti locali "#/$defs/...". Le parole chiave non elencate sono ignorate.
 */
final class JsonSchemaValidator
{
    /**
     * @param array<string,mixed> $schema
     * @return list<ValidationError>
     */
    public function validate(mixed $data, array $schema): array
    {
        return $this->check($data, $schema, $schema, '');
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $root
     * @return list<ValidationError>
     */
    private function check(mixed $data, array $schema, array $root, string $path): array
    {
        $errors = [];

        if (isset($schema['$ref'])) {
            $errors = array_merge($errors, $this->check($data, $this->resolve((string) $schema['$ref'], $root), $root, $path));
        }

        if (isset($schema['type'])) {
            $types = is_string($schema['type']) ? [$schema['type']] : $this->asList($schema['type'], 'type');
            if (!$this->isAnyType($data, $types)) {
                $errors[] = new ValidationError(
                    $path,
                    sprintf(/* translators: 1: expected types, 2: found type */ __('expected type: %1$s, found %2$s', 'nucleo'), implode(' or ', $types), $this->kindOf($data)),
                    $this->got($data),
                    $types,
                );
                return $errors;
            }
        }

        if (array_key_exists('enum', $schema)) {
            $enum = $this->asList($schema['enum'], 'enum');
            if (!$this->inList($data, $enum)) {
                $errors[] = new ValidationError($path, __('value not allowed', 'nucleo'), $this->got($data), $enum);
            }
        }

        if (array_key_exists('const', $schema) && !$this->same($data, $schema['const'])) {
            $errors[] = new ValidationError($path, __('value differs from the expected one', 'nucleo'), $this->got($data), [$schema['const']]);
        }

        if (is_string($data)) {
            $errors = array_merge($errors, $this->checkString($data, $schema, $path));
        }

        if (is_int($data) || is_float($data)) {
            $errors = array_merge($errors, $this->checkNumber($data, $schema, $path));
        }

        if (is_array($data) && array_is_list($data)) {
            $errors = array_merge($errors, $this->checkList($data, $schema, $root, $path));
        }

        if (is_array($data) && (!array_is_list($data) || $data === [])) {
            $errors = array_merge($errors, $this->checkObject($data, $schema, $root, $path));
        }

        if (isset($schema['allOf'])) {
            foreach ($schema['allOf'] as $sub) {
                $errors = array_merge($errors, $this->check($data, $sub, $root, $path));
            }
        }

        if (isset($schema['anyOf'])) {
            $errors = array_merge($errors, $this->branches($data, $schema['anyOf'], $root, $path, 'anyOf', false));
        }

        if (isset($schema['oneOf'])) {
            $errors = array_merge($errors, $this->branches($data, $schema['oneOf'], $root, $path, 'oneOf', true));
        }

        if (array_key_exists('not', $schema) && $this->check($data, $schema['not'], $root, $path) === []) {
            $errors[] = new ValidationError($path, __('must not match the forbidden schema (not)', 'nucleo'), $this->got($data));
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $schema
     * @return list<ValidationError>
     */
    private function checkString(string $value, array $schema, string $path): array
    {
        $errors = [];
        $length = mb_strlen($value);

        if (isset($schema['minLength']) && $length < $schema['minLength']) {
            $errors[] = new ValidationError($path, sprintf(/* translators: %s: minimum length */ __('minimum length: %s characters', 'nucleo'), $schema['minLength']), $this->got($value));
        }
        if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
            $errors[] = new ValidationError($path, sprintf(/* translators: %s: maximum length */ __('maximum length: %s characters', 'nucleo'), $schema['maxLength']), $this->got($value));
        }
        if (isset($schema['pattern']) && !$this->matches((string) $schema['pattern'], $value)) {
            $errors[] = new ValidationError($path, sprintf(/* translators: %s: pattern */ __('does not match pattern %s', 'nucleo'), $schema['pattern']), $this->got($value));
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $schema
     * @return list<ValidationError>
     */
    private function checkNumber(int|float $value, array $schema, string $path): array
    {
        $errors = [];

        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = new ValidationError($path, sprintf(/* translators: %s: minimum value */ __('minimum value: %s', 'nucleo'), $schema['minimum']), $this->got($value));
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = new ValidationError($path, sprintf(/* translators: %s: maximum value */ __('maximum value: %s', 'nucleo'), $schema['maximum']), $this->got($value));
        }

        return $errors;
    }

    /**
     * @param list<mixed> $list
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $root
     * @return list<ValidationError>
     */
    private function checkList(array $list, array $schema, array $root, string $path): array
    {
        $errors = [];
        $count = count($list);

        if (isset($schema['minItems']) && $count < $schema['minItems']) {
            $errors[] = new ValidationError($path, sprintf(/* translators: %s: minimum number of items */ __('at least %s items', 'nucleo'), $schema['minItems']));
        }
        if (isset($schema['maxItems']) && $count > $schema['maxItems']) {
            $errors[] = new ValidationError($path, sprintf(/* translators: %s: maximum number of items */ __('at most %s items', 'nucleo'), $schema['maxItems']));
        }
        if (isset($schema['items'])) {
            foreach ($list as $i => $item) {
                $errors = array_merge($errors, $this->check($item, $schema['items'], $root, $this->child($path, (string) $i)));
            }
        }

        return $errors;
    }

    /**
     * @param array<array-key,mixed> $obj
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $root
     * @return list<ValidationError>
     */
    private function checkObject(array $obj, array $schema, array $root, string $path): array
    {
        $errors = [];

        foreach ($schema['required'] ?? [] as $name) {
            if (!array_key_exists($name, $obj)) {
                $errors[] = new ValidationError($this->child($path, (string) $name), __('required field missing', 'nucleo'));
            }
        }
        if (isset($schema['minProperties']) && count($obj) < $schema['minProperties']) {
            $errors[] = new ValidationError($path, sprintf(/* translators: %s: minimum number of properties */ __('at least %s properties', 'nucleo'), $schema['minProperties']), count($obj));
        }

        $properties = $schema['properties'] ?? [];
        $patterns = $schema['patternProperties'] ?? [];

        foreach ($properties as $name => $sub) {
            if (array_key_exists($name, $obj)) {
                $errors = array_merge($errors, $this->check($obj[$name], $sub, $root, $this->child($path, (string) $name)));
            }
        }

        foreach ($obj as $key => $value) {
            $name = (string) $key;
            $childPath = $this->child($path, $name);
            $covered = array_key_exists($key, $properties);

            foreach ($patterns as $pattern => $sub) {
                if ($this->matches((string) $pattern, $name)) {
                    $covered = true;
                    $errors = array_merge($errors, $this->check($value, $sub, $root, $childPath));
                }
            }

            if (!$covered && array_key_exists('additionalProperties', $schema)) {
                $extra = $schema['additionalProperties'];
                if ($extra === false) {
                    $errors[] = new ValidationError($childPath, __('property not allowed', 'nucleo'), $this->got($value));
                } elseif (is_array($extra)) {
                    $errors = array_merge($errors, $this->check($value, $extra, $root, $childPath));
                }
            }
        }

        return $errors;
    }

    /**
     * anyOf e oneOf. Senza un ramo valido: se un solo ramo ha meno errori degli altri
     * restituisce i suoi errori, altrimenti un solo errore con il numero di rami.
     *
     * @param list<array<string,mixed>> $branches
     * @param array<string,mixed> $root
     * @return list<ValidationError>
     */
    private function branches(mixed $data, array $branches, array $root, string $path, string $keyword, bool $exactlyOne): array
    {
        $results = [];
        $valid = 0;
        foreach ($branches as $branch) {
            $branchErrors = $this->check($data, $branch, $root, $path);
            if ($branchErrors === []) {
                $valid++;
            }
            $results[] = $branchErrors;
        }

        if ($exactlyOne ? $valid === 1 : $valid >= 1) {
            return [];
        }

        $total = count($results);
        if ($valid > 0) {
            return [new ValidationError($path, sprintf(/* translators: 1: anyOf or oneOf, 2: valid branches, 3: total branches */ __('more than one %1$s branch is valid (%2$d of %3$d): exactly one must match', 'nucleo'), $keyword, $valid, $total))];
        }

        if ($results !== []) {
            $counts = array_map('count', $results);
            $best = array_keys($counts, min($counts), true);
            if (count($best) === 1) {
                return $results[$best[0]];
            }
        }

        return [new ValidationError($path, sprintf(/* translators: 1: number of branches, 2: anyOf or oneOf */ __('none of the %1$d branches of %2$s is valid', 'nucleo'), $total, $keyword), $this->got($data))];
    }

    /**
     * @param array<string,mixed> $root
     * @return array<string,mixed>
     */
    private function resolve(string $ref, array $root): array
    {
        $prefix = '#/$defs/';
        if (!str_starts_with($ref, $prefix)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException(sprintf(/* translators: %s: reference */ __('%s is not supported: only references "#/$defs/..." are allowed', 'nucleo'), $ref));
        }

        $name = strtr(substr($ref, strlen($prefix)), ['~1' => '/', '~0' => '~']);
        $target = $root['$defs'][$name] ?? null;
        if (!is_array($target)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException(sprintf(/* translators: %s: reference */ __('%s not found in the schema', 'nucleo'), $ref));
        }

        return $target;
    }

    /** @return list<mixed> */
    private function asList(mixed $value, string $keyword): array
    {
        if (!is_array($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException(sprintf(/* translators: %s: keyword name */ __('%s must be a list', 'nucleo'), $keyword));
        }

        return array_values($value);
    }

    /** @param list<mixed> $types */
    private function isAnyType(mixed $data, array $types): bool
    {
        foreach ($types as $type) {
            if ($this->isType($data, (string) $type)) {
                return true;
            }
        }

        return false;
    }

    private function isType(mixed $data, string $type): bool
    {
        return match ($type) {
            'string' => is_string($data),
            'integer' => is_int($data) || (is_float($data) && is_finite($data) && floor($data) === $data),
            'number' => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            'null' => $data === null,
            'array' => is_array($data) && array_is_list($data),
            'object' => is_array($data) && (!array_is_list($data) || $data === []),
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            default => throw new \InvalidArgumentException(sprintf(/* translators: %s: type name */ __('unknown type: %s', 'nucleo'), $type)),
        };
    }

    /** @param list<mixed> $list */
    private function inList(mixed $data, array $list): bool
    {
        foreach ($list as $item) {
            if ($this->same($data, $item)) {
                return true;
            }
        }

        return false;
    }

    /** Uguaglianza JSON: 1 e 1.0 sono uguali, gli oggetti non dipendono dall'ordine delle chiavi. */
    private function same(mixed $a, mixed $b): bool
    {
        $isNumber = static fn (mixed $v): bool => is_int($v) || is_float($v);
        if ($isNumber($a) && $isNumber($b)) {
            return $a == $b;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !$this->same($value, $b[$key])) {
                    return false;
                }
            }
            return true;
        }

        return $a === $b;
    }

    private function matches(string $pattern, string $value): bool
    {
        // Il delimitatore è '#': i '#' non ancora escapati nel pattern vanno escapati.
        $regex = '#' . preg_replace('/(?<!\\\\)#/', '\\\\#', $pattern) . '#u';
        $result = preg_match($regex, $value);
        if ($result === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException(sprintf(/* translators: %s: pattern */ __('invalid pattern: %s', 'nucleo'), $pattern));
        }

        return $result === 1;
    }

    /** Percorso JSON Pointer (RFC 6901) del figlio. */
    private function child(string $path, string $key): string
    {
        return $path . '/' . str_replace(['~', '/'], ['~0', '~1'], $key);
    }

    private function kindOf(mixed $data): string
    {
        return match (true) {
            $data === null => 'null',
            is_bool($data) => 'boolean',
            is_int($data) => 'integer',
            is_float($data) => 'number',
            is_string($data) => 'string',
            is_array($data) && array_is_list($data) => 'array',
            default => 'object',
        };
    }

    /** Valore da riportare nell'errore: lo scalare così com'è, per array e oggetti il tipo. */
    private function got(mixed $data): mixed
    {
        return is_array($data) ? $this->kindOf($data) : $data;
    }
}
