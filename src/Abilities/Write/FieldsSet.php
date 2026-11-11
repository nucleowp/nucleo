<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Abilities\Read\FieldsGet;
use Nucleo\Content\FieldValues;

/** nucleo/fields-set: salva i valori dei campi di un contenuto (postId) o di un termine (termId), in modo parziale. */
final class FieldsSet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/fields-set';
    }

    public function args(): array
    {
        return [
            'label' => __('Save field values', 'nucleo'),
            'description' => 'Saves field values of a post (postId) or a term (termId). Partial: keys left out are kept. An empty value clears the field. Every value is checked before anything is written. With "base" (the hash from fields-get), nothing is written if the values changed since: error code "conflict". Success returns the new "hash".',
            'input_schema' => [
                'type' => 'object',
                'required' => ['values'],
                'properties' => [
                    'postId' => ['type' => 'integer', 'description' => 'Post ID.'],
                    'termId' => ['type' => 'integer', 'description' => 'Term ID.'],
                    'values' => ['type' => 'object', 'description' => 'Values by field key. Repeaters are lists of rows.'],
                    'base' => ['type' => 'string', 'description' => 'Hash of the values read with fields-get. Optional.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'hash' => ['type' => 'string', 'description' => 'Hash of the saved values: the new "base" for the next fields-set.'],
                    'errors' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'code' => ['type' => 'string'],
                                'path' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'error' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function execute(array $input): array
    {
        $target = self::target($input);
        if ($target === null) {
            return ['ok' => false, 'error' => ['code' => 'invalid_input', 'message' => __('Give either "postId" or "termId".', 'nucleo')]];
        }

        $values = $input['values'] ?? null;
        if (!is_array($values)) {
            return ['ok' => false, 'error' => ['code' => 'invalid_input', 'message' => __('"values" must be an object.', 'nucleo')]];
        }

        $hasBase = array_key_exists('base', $input);
        if ($hasBase && !is_string($input['base'])) {
            return ['ok' => false, 'error' => ['code' => 'invalid_input', 'message' => __('"base" must be a string.', 'nucleo')]];
        }

        [$kind, $id] = $target;
        if (FieldValues::fields($kind, $id) === []) {
            return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => __('No content type or taxonomy with fields has this ID.', 'nucleo')]];
        }

        if ($hasBase && FieldsGet::hashOf(FieldValues::get($kind, $id)) !== $input['base']) {
            return ['ok' => false, 'error' => ['code' => 'conflict', 'message' => __('The values changed since they were read: read them again before saving.', 'nucleo')]];
        }

        $written = FieldValues::set($kind, $id, $values);
        if (!$written['ok']) {
            return $written;
        }

        return ['ok' => true, 'hash' => FieldsGet::hashOf(FieldValues::get($kind, $id))];
    }

    public function permitted(array $input): bool
    {
        $target = self::target($input);
        if ($target === null) {
            return false;
        }

        [$kind, $id] = $target;

        return $kind === 'post' ? current_user_can('edit_post', $id) : current_user_can('edit_term', $id);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:string,1:int}|null
     */
    private static function target(array $input): ?array
    {
        $hasPost = isset($input['postId']);
        $hasTerm = isset($input['termId']);
        if ($hasPost === $hasTerm) {
            return null;
        }

        return $hasPost ? ['post', (int) $input['postId']] : ['term', (int) $input['termId']];
    }
}
