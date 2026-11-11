<?php

declare(strict_types=1);

namespace Nucleo\Content;

defined('ABSPATH') || exit;

/** Controlli nativi di un campo dei contenuti e dei termini (docs/contracts/content-model.md, fase 2). */
final class FieldInput
{
    /**
     * Stampa il controllo del campo. $name è il nome HTML: nucleo_fields[prezzo], oppure nucleo_fields[programma][0][titolo] nelle righe.
     *
     * @param array<string,mixed> $field
     */
    public static function render(array $field, mixed $value, string $name): void
    {
        match ((string) ($field['type'] ?? 'text')) {
            'repeater' => self::repeater($field, $value, $name),
            'image' => self::image($field, $value, $name),
            'relation' => self::relation($field, $value, $name),
            'boolean' => self::boolean($field, $value, $name),
            default => self::simple($field, $value, $name),
        };
    }

    /** @param array<string,mixed> $field */
    private static function simple(array $field, mixed $value, string $name): void
    {
        echo '<label class="nc-field">';
        echo '<span class="nc-field__label">' . esc_html(self::label($field)) . '</span>';
        self::control($field, $value, $name);
        self::help($field);
        echo '</label>';
    }

    /** @param array<string,mixed> $field */
    private static function control(array $field, mixed $value, string $name): void
    {
        $type = (string) $field['type'];

        if ($type === 'textarea') {
            echo '<textarea class="nc-field__input" name="' . esc_attr($name) . '"';
            self::requiredAttr($field);
            echo '>' . esc_textarea(self::text($value)) . '</textarea>';

            return;
        }

        if ($type === 'select') {
            self::select($field, $value, $name);

            return;
        }

        $inputType = match ($type) {
            'number', 'url', 'email', 'date' => $type,
            default => 'text',
        };
        echo '<input class="nc-field__input" type="' . esc_attr($inputType) . '" name="' . esc_attr($name) . '" value="' . esc_attr(self::text($value)) . '"';
        if (isset($field['min'])) {
            echo ' min="' . esc_attr((string) $field['min']) . '"';
        }
        if (isset($field['max'])) {
            echo ' max="' . esc_attr((string) $field['max']) . '"';
        }
        if (isset($field['step'])) {
            echo ' step="' . esc_attr((string) $field['step']) . '"';
        }
        self::requiredAttr($field);
        echo '>';
    }

    /** @param array<string,mixed> $field */
    private static function select(array $field, mixed $value, string $name): void
    {
        $multiple = !empty($field['multiple']);
        $current = match (true) {
            is_array($value) => array_map('strval', $value),
            $value === null || $value === '' => [],
            default => [(string) $value],
        };

        if ($multiple) {
            // Senza selezioni il campo non arriva in POST: il hidden vuoto (senza []) lo fa arrivare come '' e FieldValues cancella il meta.
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="">';
        }
        echo '<select class="nc-field__input" name="' . esc_attr($name . ($multiple ? '[]' : '')) . '"';
        if ($multiple) {
            echo ' multiple';
        }
        self::requiredAttr($field);
        echo '>';
        if (!$multiple) {
            echo '<option value="">' . '&mdash;' . '</option>';
        }
        foreach ($field['options'] ?? [] as $option) {
            $optionValue = (string) $option['value'];
            echo '<option value="' . esc_attr($optionValue) . '"' . selected(in_array($optionValue, $current, true), true, false) . '>' . esc_html((string) $option['label']) . '</option>';
        }
        echo '</select>';
    }

    /** @param array<string,mixed> $field */
    private static function boolean(array $field, mixed $value, string $name): void
    {
        // Il hidden 0 precede il checkbox: se il checkbox non è spuntato arriva comunque 0.
        $checked = $value === true || $value === 1 || $value === '1';

        echo '<div class="nc-field">';
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="0">';
        echo '<label class="nc-check"><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked($checked, true, false) . '>';
        echo '<span>' . esc_html(self::label($field)) . '</span></label>';
        self::help($field);
        echo '</div>';
    }

    /** @param array<string,mixed> $field */
    private static function image(array $field, mixed $value, string $name): void
    {
        $id = is_int($value) && $value > 0 ? $value : 0;

        echo '<fieldset class="nc-field nc-image">';
        echo '<legend class="nc-field__label">' . esc_html(self::label($field)) . '</legend>';
        echo '<input class="nc-image__input" type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($id > 0 ? (string) $id : '') . '">';
        echo '<div class="nc-image__preview">' . wp_kses_post($id > 0 ? wp_get_attachment_image($id, 'thumbnail') : '') . '</div>';
        echo '<p class="nc-image__actions">';
        echo '<button type="button" class="nc-btn nc-btn--secondary nc-btn--small" data-action="choose">' . esc_html__('Choose', 'nucleo') . '</button> ';
        // Rimuovi compare solo quando c'è un'immagine (fields.js lo mostra e lo nasconde).
        echo '<button type="button" class="nc-btn nc-btn--danger nc-btn--small" data-action="remove"' . ($id > 0 ? '' : ' hidden') . '>' . esc_html__('Remove', 'nucleo') . '</button>';
        echo '</p>';
        self::help($field);
        echo '</fieldset>';
    }

    /** @param array<string,mixed> $field */
    private static function relation(array $field, mixed $value, string $name): void
    {
        $multiple = !empty($field['multiple']);
        $ids = match (true) {
            is_array($value) => array_map('intval', $value),
            is_int($value) => [$value],
            default => [],
        };
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        $inputName = $name . ($multiple ? '[]' : '');
        $types = implode(',', array_map('strval', $field['postTypes'] ?? []));

        echo '<fieldset class="nc-field nc-relation" data-name="' . esc_attr($inputName) . '" data-multiple="' . ($multiple ? '1' : '0') . '" data-post-types="' . esc_attr($types) . '" data-remove-label="' . esc_attr__('Remove', 'nucleo') . '">';
        echo '<legend class="nc-field__label">' . esc_html(self::label($field)) . '</legend>';
        // Hidden vuoto senza []: senza selezioni arriva '' e il campo si cancella; con selezioni il valore successivo lo sostituisce.
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="">';
        echo '<ul class="nc-relation__items">';
        foreach ($ids as $id) {
            echo '<li class="nc-relation__item" data-id="' . esc_attr((string) $id) . '">';
            echo '<input type="hidden" name="' . esc_attr($inputName) . '" value="' . esc_attr((string) $id) . '">';
            echo '<span>' . esc_html(get_the_title($id)) . '</span> ';
            echo '<button type="button" class="nc-btn nc-btn--danger nc-btn--small" data-action="remove">' . esc_html__('Remove', 'nucleo') . '</button>';
            echo '</li>';
        }
        echo '</ul>';
        echo '<input class="nc-relation__search" type="search" placeholder="' . esc_attr__('Search content', 'nucleo') . '" aria-label="' . esc_attr(self::label($field)) . '">';
        echo '<ul class="nc-relation__results" hidden></ul>';
        self::help($field);
        echo '</fieldset>';
    }

    /** @param array<string,mixed> $field */
    private static function repeater(array $field, mixed $value, string $name): void
    {
        $rows = is_array($value) ? array_values($value) : [];
        $subs = $field['fields'] ?? [];
        $max = isset($field['max']) ? (string) $field['max'] : '';

        echo '<fieldset class="nc-field nc-repeater" data-name="' . esc_attr($name) . '" data-max="' . esc_attr($max) . '">';
        echo '<legend class="nc-field__label">' . esc_html(self::label($field)) . '</legend>';
        // Hidden vuoto: senza righe arriva '' e il ripetitore si cancella; con righe PHP lo sostituisce con la lista.
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="">';
        echo '<div class="nc-repeater__rows">';
        foreach ($rows as $i => $row) {
            self::row($subs, is_array($row) ? $row : [], $name . '[' . $i . ']');
        }
        echo '</div>';
        echo '<template class="nc-repeater__template">';
        self::row($subs, [], $name . '[__i__]');
        echo '</template>';
        echo '<p><button type="button" class="nc-btn nc-btn--secondary nc-btn--small" data-action="add">' . esc_html__('Add row', 'nucleo') . '</button></p>';
        self::help($field);
        echo '</fieldset>';
    }

    /**
     * @param list<array<string,mixed>> $subs
     * @param array<array-key,mixed> $row
     */
    private static function row(array $subs, array $row, string $base): void
    {
        echo '<div class="nc-repeater__row">';
        echo '<div class="nc-repeater__fields">';
        foreach ($subs as $sub) {
            self::render($sub, $row[$sub['key']] ?? null, $base . '[' . $sub['key'] . ']');
        }
        echo '</div>';
        echo '<p class="nc-repeater__actions">';
        echo '<button type="button" class="nc-btn nc-btn--tertiary nc-btn--small" data-action="up">' . esc_html__('Up', 'nucleo') . '</button> ';
        echo '<button type="button" class="nc-btn nc-btn--tertiary nc-btn--small" data-action="down">' . esc_html__('Down', 'nucleo') . '</button> ';
        echo '<button type="button" class="nc-btn nc-btn--danger nc-btn--small" data-action="remove">' . esc_html__('Remove', 'nucleo') . '</button>';
        echo '</p>';
        echo '</div>';
    }

    /** @param array<string,mixed> $field */
    private static function label(array $field): string
    {
        return (string) ($field['label'] ?? $field['key'] ?? '');
    }

    /** @param array<string,mixed> $field */
    private static function help(array $field): void
    {
        if (!empty($field['help'])) {
            echo '<small class="nc-field__help">' . esc_html((string) $field['help']) . '</small>';
        }
    }

    /** @param array<string,mixed> $field */
    private static function requiredAttr(array $field): void
    {
        if (!empty($field['required'])) {
            echo ' required';
        }
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
