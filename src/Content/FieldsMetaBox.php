<?php

declare(strict_types=1);

namespace Nucleo\Content;

defined('ABSPATH') || exit;

use Nucleo\Admin\Assets;
use Nucleo\Admin\Editor;

/**
 * Riquadri "Campi" nella schermata di modifica dei tipi con gruppi, e asset dei campi (docs/contracts/content-model.md, fase 2).
 * I campi dei termini sono in TermFields.
 */
final class FieldsMetaBox
{
    public const NONCE = 'nucleo_fields';

    public const NONCE_FIELD = 'nucleo_fields_nonce';

    public const INPUT = 'nucleo_fields';

    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'addBoxes']);
        add_action('save_post', [self::class, 'save']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_notices', [self::class, 'notices']);
        TermFields::register();
    }

    /** Un riquadro per gruppo con location sul tipo. */
    public static function addBoxes(string $postType): void
    {
        foreach (self::groups() as $group) {
            if (($group['location']['postType'] ?? null) === $postType) {
                add_meta_box(
                    'nucleo-fields-' . $group['id'],
                    $group['title'],
                    [self::class, 'render'],
                    $postType,
                    'normal',
                    'default',
                    ['group' => $group['id']],
                );
            }
        }
    }

    /** @param array<string,mixed> $box */
    public static function render(\WP_Post $post, array $box): void
    {
        $args = is_array($box['args'] ?? null) ? $box['args'] : [];
        $group = self::group((string) ($args['group'] ?? ''));
        if ($group === null) {
            return;
        }

        $values = FieldValues::get('post', $post->ID);
        echo '<div class="nucleo-admin nc-fields">';
        wp_nonce_field(self::NONCE, self::NONCE_FIELD);
        foreach ($group['fields'] ?? [] as $field) {
            FieldInput::render($field, $values[$field['key']] ?? null, self::INPUT . '[' . $field['key'] . ']');
        }
        echo '</div>';
    }

    /**
     * Salva i campi del tipo presenti nel POST. Esce senza nonce valido, in autosave, nelle revisioni o senza edit_post.
     */
    public static function save(int $postId): void
    {
        if (!self::verified() || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId) || !current_user_can('edit_post', $postId)) {
            return;
        }

        $values = self::input(FieldValues::fields('post', $postId));
        if ($values === []) {
            return;
        }

        $result = FieldValues::set('post', $postId, $values);
        if (!$result['ok']) {
            self::flash($result['errors'] ?? []);
        }
    }

    public static function enqueue(): void
    {
        $screen = get_current_screen();
        if ($screen === null) {
            return;
        }

        $onPost = in_array($screen->base, ['post', 'post-new'], true) && self::hasGroups('postType', (string) $screen->post_type);
        $onTerm = in_array($screen->base, ['edit-tags', 'term'], true) && self::hasGroups('taxonomy', (string) $screen->taxonomy);
        if (!$onPost && !$onTerm) {
            return;
        }

        $base = plugin_dir_url(dirname(__DIR__, 2) . '/nucleo.php');
        // Componenti condivisi (nc-btn e campi) da admin.css: il box ha la classe nucleo-admin.
        Assets::registerComponents($base);
        wp_register_style('nucleo-fields', $base . 'assets/admin/fields.css', ['nucleo-admin'], Editor::assetVersion('assets/admin/fields.css'));
        wp_register_script('nucleo-fields', $base . 'assets/admin/fields.js', ['media-editor'], Editor::assetVersion('assets/admin/fields.js'), true);
        wp_localize_script('nucleo-fields', 'nucleoFields', [
            'restUrl' => rest_url(),
            'nonce' => wp_create_nonce('wp_rest'),
            'i18n' => [
                'empty' => __('No results.', 'nucleo'),
                'mediaTitle' => __('Choose an image', 'nucleo'),
                'mediaButton' => __('Use this image', 'nucleo'),
            ],
        ]);

        wp_enqueue_media();
        wp_enqueue_style('nucleo-fields');
        wp_enqueue_script('nucleo-fields');
    }

    /** Errori del salvataggio precedente, per l'utente corrente. */
    public static function notices(): void
    {
        $messages = get_transient(self::errorsKey());
        if (!is_array($messages) || $messages === []) {
            return;
        }

        delete_transient(self::errorsKey());
        echo '<div class="notice notice-error"><p>' . esc_html__('Fields not saved:', 'nucleo') . '</p><ul>';
        foreach ($messages as $message) {
            echo '<li>' . esc_html((string) $message) . '</li>';
        }
        echo '</ul></div>';
    }

    public static function verified(): bool
    {
        $nonce = isset($_POST[self::NONCE_FIELD]) && is_string($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]))
            : '';

        return $nonce !== '' && wp_verify_nonce($nonce, self::NONCE) !== false;
    }

    /**
     * Valori dei campi indicati, letti dal POST dopo la verifica del nonce e sanificati per tipo.
     * La validazione completa è in FieldValues::set.
     *
     * @param list<array<string,mixed>> $fields
     * @return array<string,mixed>
     */
    public static function input(array $fields): array
    {
        $nonce = isset($_POST[self::NONCE_FIELD]) && is_string($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]))
            : '';
        if ($nonce === '' || wp_verify_nonce($nonce, self::NONCE) === false || !isset($_POST[self::INPUT]) || !is_array($_POST[self::INPUT])) {
            return [];
        }

        $values = [];
        foreach ($fields as $field) {
            $key = (string) $field['key'];
            if (array_key_exists($key, $_POST[self::INPUT])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanificato per tipo da clean().
                $values[$key] = self::clean($field, wp_unslash($_POST[self::INPUT][$key]));
            }
        }

        return $values;
    }

    /**
     * Sanifica un valore secondo il tipo del campo: testo, testo su più righe, URL, email; liste e righe dei
     * repeater campo per campo.
     *
     * @param array<string,mixed> $field
     */
    private static function clean(array $field, mixed $raw): mixed
    {
        if (is_array($raw)) {
            if (($field['type'] ?? '') !== 'repeater') {
                return array_map(static fn (mixed $item): mixed => self::clean(['type' => $field['type'] ?? ''], $item), $raw);
            }
            $subs = array_column(is_array($field['fields'] ?? null) ? $field['fields'] : [], null, 'key');
            $rows = [];
            foreach ($raw as $i => $row) {
                $cells = null;
                if (is_array($row)) {
                    $cells = [];
                    foreach ($row as $key => $cell) {
                        $cells[$key] = self::clean($subs[$key] ?? ['type' => 'text'], $cell);
                    }
                }
                $rows[$i] = $cells;
            }

            return $rows;
        }
        if (!is_string($raw)) {
            return is_scalar($raw) ? $raw : null;
        }

        return match ($field['type'] ?? '') {
            'textarea' => sanitize_textarea_field($raw),
            'url' => sanitize_url($raw),
            'email' => sanitize_email($raw),
            default => sanitize_text_field($raw),
        };
    }

    /** @param list<array{code:string,path:string,message:string}> $errors */
    public static function flash(array $errors): void
    {
        $messages = array_map(static fn (array $error): string => $error['path'] . ': ' . $error['message'], $errors);
        set_transient(self::errorsKey(), $messages, 5 * MINUTE_IN_SECONDS);
    }

    /**
     * Gruppi di campi del modello salvato.
     *
     * @return list<array<string,mixed>>
     */
    public static function groups(): array
    {
        return ModelRepository::model()['fieldGroups'];
    }

    /** @return array<string,mixed>|null */
    public static function group(string $id): ?array
    {
        foreach (self::groups() as $group) {
            if ($group['id'] === $id) {
                return $group;
            }
        }

        return null;
    }

    private static function hasGroups(string $kind, string $name): bool
    {
        foreach (self::groups() as $group) {
            if (($group['location'][$kind] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    private static function errorsKey(): string
    {
        return 'nucleo_fields_errors_' . get_current_user_id();
    }
}
