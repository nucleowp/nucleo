<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Content\ModelRepository;

/**
 * nucleo/bind-fields: campi a cui un nodo può collegarsi per un tipo di contenuto.
 * Raccoglie meta WordPress, ACF e Meta Box; il filtro nucleo/bind_fields può aggiungerne altri.
 */
final class BindFields implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/bind-fields';
    }

    public function args(): array
    {
        return [
            'label' => __('Bindable fields', 'nucleo'),
            'description' => 'Fields of the content type a block can bind to: Nucleo model fields, WordPress meta, ACF, Meta Box and sources added by the filter. "writable" says whether content-upsert can write the field: only source nucleo (fields of the Nucleo content model) is writable; the others are read-only for Nucleo and "readOnly" says why. Edit those fields in the plugin that owns them: declaring the same key in model-set would replace its registration and value format.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['postType'],
                'additionalProperties' => false,
                'properties' => [
                    'postType' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'fields' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['source', 'key', 'label', 'writable'],
                            'properties' => [
                                'source' => ['type' => 'string'],
                                'key' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'writable' => ['type' => 'boolean', 'description' => 'content-upsert accepts the key in "fields".'],
                                'readOnly' => ['type' => 'string', 'description' => 'Why content-upsert does not write it. Only when writable is false.'],
                            ],
                        ],
                    ],
                    'error' => ['type' => 'object'],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function execute(array $input): array
    {
        $postType = sanitize_key((string) ($input['postType'] ?? ''));
        if ($postType === '' || !post_type_exists($postType)) {
            return ['ok' => false, 'error' => ['code' => 'invalid_post_type', 'message' => __('Invalid content type.', 'nucleo')]];
        }

        $fields = [];

        // Le chiavi del modello dei contenuti compaiono solo con la sorgente nucleo.
        $modelKeys = array_column(self::modelFields($postType), 'key');

        foreach (get_registered_meta_keys('post', $postType) as $key => $meta) {
            if (in_array((string) $key, $modelKeys, true)) {
                continue;
            }
            $description = is_array($meta) ? ($meta['description'] ?? null) : null;
            $fields[] = [
                'source' => 'post',
                'key' => (string) $key,
                'label' => self::label($description, (string) $key),
            ];
        }

        foreach (self::nucleoFields($postType) as $field) {
            $fields[] = $field;
        }

        if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
            foreach (acf_get_field_groups(['post_type' => $postType]) as $group) {
                foreach (acf_get_fields($group) ?: [] as $field) {
                    if (!is_array($field) || !isset($field['name'])) {
                        continue;
                    }
                    $fields[] = [
                        'source' => 'acf',
                        'key' => (string) $field['name'],
                        'label' => self::label($field['label'] ?? null, (string) $field['name']),
                    ];
                }
            }
        }

        if (function_exists('rwmb_get_object_fields')) {
            foreach (rwmb_get_object_fields($postType) as $id => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $fields[] = [
                    'source' => 'metabox',
                    'key' => (string) ($field['id'] ?? $id),
                    'label' => self::label($field['name'] ?? null, (string) ($field['id'] ?? $id)),
                ];
            }
        }

        $filtered = apply_filters('nucleo/bind_fields', $fields, $postType);

        // Le voci aggiunte dal filtro arrivano da altro codice: si tengono solo quelle ben formate.
        $valid = [];
        foreach (is_array($filtered) ? $filtered : [] as $field) {
            if (is_array($field) && is_string($field['source'] ?? null) && is_string($field['key'] ?? null) && is_string($field['label'] ?? null)) {
                $valid[] = self::access(['source' => $field['source'], 'key' => $field['key'], 'label' => $field['label']]);
            }
        }

        return ['ok' => true, 'fields' => $valid];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * Campi del modello dei contenuti per il tipo. Un ripetitore dà una voce per sottocampo, con indice 0.
     *
     * @return list<array{source: string, key: string, label: string}>
     */
    private static function nucleoFields(string $postType): array
    {
        $fields = [];
        foreach (self::modelFields($postType) as $field) {
            if ($field['type'] !== 'repeater') {
                $fields[] = ['source' => 'nucleo', 'key' => $field['key'], 'label' => $field['label']];
                continue;
            }
            foreach ($field['fields'] ?? [] as $sub) {
                $fields[] = [
                    'source' => 'nucleo',
                    'key' => $field['key'] . '.0.' . $sub['key'],
                    'label' => $field['label'] . ' / ' . $sub['label'],
                ];
            }
        }

        return $fields;
    }

    /**
     * Campi di primo livello del modello per il tipo.
     *
     * @return list<array{key: string, type: string, label: string, options?: list<array{value: string, label: string}>, fields?: list<array<string,mixed>>}>
     */
    private static function modelFields(string $postType): array
    {
        $fields = [];
        foreach (ModelRepository::model()['fieldGroups'] as $group) {
            if (($group['location']['postType'] ?? null) === $postType) {
                foreach ($group['fields'] as $field) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * content-upsert scrive solo i campi del modello Nucleo (FieldValues): le altre sorgenti sono in sola lettura.
     *
     * @param array{source: string, key: string, label: string} $field
     * @return array{source: string, key: string, label: string, writable: bool, readOnly?: string}
     */
    private static function access(array $field): array
    {
        if ($field['source'] === 'nucleo') {
            return $field + ['writable' => true];
        }
        $owner = match ($field['source']) {
            'post' => 'WordPress meta registered by another plugin or the theme',
            'acf' => 'ACF field',
            'metabox' => 'Meta Box field',
            default => 'field of source ' . $field['source'],
        };

        return $field + ['writable' => false, 'readOnly' => $owner . ': content-upsert writes only fields of the Nucleo content model. Edit it in the plugin that owns it; do not declare the same key in model-set.'];
    }

    /** Etichetta del campo: il testo indicato, o la chiave se manca. */
    private static function label(mixed $raw, string $fallback): string
    {
        return is_string($raw) && $raw !== '' ? $raw : $fallback;
    }
}
