<?php

declare(strict_types=1);

namespace Nucleo\Content;

defined('ABSPATH') || exit;

/** Campi dei termini nelle schermate di aggiunta e modifica delle tassonomie con gruppi (docs/contracts/content-model.md, fase 2). */
final class TermFields
{
    public static function register(): void
    {
        foreach (self::taxonomies() as $taxonomy) {
            add_action("{$taxonomy}_add_form_fields", [self::class, 'addForm']);
            add_action("{$taxonomy}_edit_form_fields", [self::class, 'editForm'], 10, 2);
            add_action("created_{$taxonomy}", [self::class, 'save']);
            add_action("edited_{$taxonomy}", [self::class, 'save']);
        }
    }

    public static function addForm(string $taxonomy): void
    {
        $fields = self::fields($taxonomy);
        if ($fields === []) {
            return;
        }

        echo '<div class="nucleo-admin nc-fields">';
        wp_nonce_field(FieldsMetaBox::NONCE, FieldsMetaBox::NONCE_FIELD);
        foreach ($fields as $field) {
            FieldInput::render($field, null, FieldsMetaBox::INPUT . '[' . $field['key'] . ']');
        }
        echo '</div>';
    }

    public static function editForm(\WP_Term $term, string $taxonomy): void
    {
        $fields = self::fields($taxonomy);
        if ($fields === []) {
            return;
        }

        $values = FieldValues::get('term', $term->term_id);
        echo '<tr class="form-field"><td colspan="2"><div class="nucleo-admin nc-fields">';
        wp_nonce_field(FieldsMetaBox::NONCE, FieldsMetaBox::NONCE_FIELD);
        foreach ($fields as $field) {
            FieldInput::render($field, $values[$field['key']] ?? null, FieldsMetaBox::INPUT . '[' . $field['key'] . ']');
        }
        echo '</div></td></tr>';
    }

    /** Salva alla creazione e alla modifica del termine: solo i campi della sua tassonomia presenti nel POST. */
    public static function save(int $termId): void
    {
        if (!FieldsMetaBox::verified() || !current_user_can('edit_term', $termId)) {
            return;
        }

        $values = FieldsMetaBox::input(FieldValues::fields('term', $termId));
        if ($values === []) {
            return;
        }

        $result = FieldValues::set('term', $termId, $values);
        if (!$result['ok']) {
            FieldsMetaBox::flash($result['errors'] ?? []);
        }
    }

    /** @return list<string> */
    public static function taxonomies(): array
    {
        $taxonomies = [];
        foreach (FieldsMetaBox::groups() as $group) {
            $taxonomy = $group['location']['taxonomy'] ?? null;
            if (is_string($taxonomy)) {
                $taxonomies[$taxonomy] = true;
            }
        }

        return array_keys($taxonomies);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function fields(string $taxonomy): array
    {
        $fields = [];
        foreach (FieldsMetaBox::groups() as $group) {
            if (($group['location']['taxonomy'] ?? null) !== $taxonomy) {
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
}
