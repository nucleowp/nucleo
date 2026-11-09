<?php

declare(strict_types=1);

namespace Nucleo\Forms;

/** Validazione lato server dei valori inviati. Ignora i campi non dichiarati e i campi context. */
final class FormValidator
{
    private const TEXT_TYPES = ['text', 'textarea', 'email', 'tel', 'url'];
    private const TEL_RE = '/^[0-9 +().-]{5,30}$/D';
    private const NUMBER_RE = '/^-?[0-9]+(\.[0-9]+)?$/D';
    private const DATE_RE = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D';

    /**
     * @param array<string,mixed> $input valori grezzi (wp_unslash già applicato)
     * @return array{values: array<string,string|list<string>>, errors: array<string,string>}
     */
    public static function validate(FormConfig $config, array $input): array
    {
        /** @var array<string,string|list<string>> $values */
        $values = [];
        /** @var array<string,string> $errors */
        $errors = [];
        foreach ($config->fields as $field) {
            $name = (string) $field['name'];
            $type = (string) $field['type'];
            if ($type === 'context') {
                continue;
            }
            $raw = $input[$name] ?? null;

            if ($type === 'checkboxes') {
                $list = self::choices($raw);
                if ($list === []) {
                    if ($field['required'] === true) {
                        $errors[$name] = __('This field is required.', 'nucleo');
                    }
                    continue;
                }
                $error = self::checkChoices($field, $list);
                if ($error === null) {
                    $values[$name] = $list;
                } else {
                    $errors[$name] = $error;
                }
                continue;
            }

            if ($type === 'hidden') {
                $text = (string) $field['value'];
            } else {
                $text = self::scalar($raw, in_array($type, self::TEXT_TYPES, true));
            }
            if ($text === '') {
                if ($field['required'] === true) {
                    $errors[$name] = __('This field is required.', 'nucleo');
                }
                continue;
            }
            $error = self::checkText($field, $text);
            if ($error === null) {
                $values[$name] = $text;
            } else {
                $errors[$name] = $error;
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Primo errore di un campo a scelta multipla, o null.
     * @param array<string,mixed> $field
     * @param list<string> $list
     */
    private static function checkChoices(array $field, array $list): ?string
    {
        return array_diff($list, self::optionValues($field)) === []
            ? null
            : __('Choose one or more of the options.', 'nucleo');
    }

    /**
     * Primo errore di un campo testuale, o null se il valore è valido.
     * @param array<string,mixed> $field
     */
    private static function checkText(array $field, string $value): ?string
    {
        return match ($field['type']) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false
                ? __('Enter a valid email address.', 'nucleo')
                : self::length($field, $value),
            'url' => self::isHttpUrl($value)
                ? self::length($field, $value)
                : __('Enter a valid web address starting with http or https.', 'nucleo'),
            'tel' => preg_match(self::TEL_RE, $value) === 1
                ? self::length($field, $value)
                : __('Enter a valid phone number.', 'nucleo'),
            'text', 'textarea' => self::length($field, $value),
            'number' => self::number($field, $value),
            'date' => self::date($field, $value),
            'select', 'radio' => in_array($value, self::optionValues($field), true)
                ? null
                : __('Choose one of the options.', 'nucleo'),
            'checkbox', 'consent' => $value === '1' ? null : __('Invalid value.', 'nucleo'),
            default => null,
        };
    }

    /** @param array<string,mixed> $field */
    private static function length(array $field, string $value): ?string
    {
        return mb_strlen($value) > $field['maxLength'] ? __('The text is too long.', 'nucleo') : null;
    }

    /** @param array<string,mixed> $field */
    private static function number(array $field, string $value): ?string
    {
        if (preg_match(self::NUMBER_RE, $value) !== 1) {
            return __('Enter a valid number.', 'nucleo');
        }
        $dot = strpos($value, '.');
        $decimals = $dot === false ? 0 : strlen($value) - $dot - 1;
        if ($decimals > $field['decimals']) {
            return __('Too many decimal places.', 'nucleo');
        }
        $number = (float) $value;
        if (
            (is_numeric($field['min']) && $number < (float) $field['min'])
            || (is_numeric($field['max']) && $number > (float) $field['max'])
        ) {
            return __('Enter a value within the allowed range.', 'nucleo');
        }

        return null;
    }

    /** @param array<string,mixed> $field */
    private static function date(array $field, string $value): ?string
    {
        $date = preg_match(self::DATE_RE, $value) === 1
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value)
            : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return __('Enter a valid date.', 'nucleo');
        }
        if (($field['min'] !== '' && $value < $field['min']) || ($field['max'] !== '' && $value > $field['max'])) {
            return __('Enter a date within the allowed range.', 'nucleo');
        }

        return null;
    }

    private static function isHttpUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) wp_parse_url($value, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }

    /**
     * @param array<string,mixed> $field
     * @return list<string>
     */
    private static function optionValues(array $field): array
    {
        return array_column($field['options'], 'value');
    }

    /**
     * Valori di un campo a scelta multipla: stringhe non vuote, senza duplicati.
     * @param mixed $raw valore grezzo: stringa singola o lista
     * @return list<string>
     */
    private static function choices(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        /** @var list<string> $out */
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item) && !is_int($item)) {
                continue;
            }
            $text = trim((string) $item);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return array_values(array_unique($out));
    }

    /** Valore scalare normalizzato: stringa senza tag (se testo) e senza spazi ai bordi. */
    private static function scalar(mixed $raw, bool $stripTags): string
    {
        if (is_bool($raw)) {
            return $raw ? '1' : '';
        }
        if (!is_scalar($raw)) {
            return '';
        }
        $text = (string) $raw;
        if ($stripTags) {
            $text = wp_strip_all_tags($text);
        }

        return trim($text);
    }
}
