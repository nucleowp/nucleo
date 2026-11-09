<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Content\FieldValues;

/** nucleo/fields-get: definizioni e valori dei campi di un contenuto (postId) o di un termine (termId). */
final class FieldsGet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/fields-get';
    }

    public function args(): array
    {
        return [
            'label' => __('Get field values', 'nucleo'),
            'description' => 'Returns the field definitions and the saved values of a post (postId) or a term (termId). A field without a value is null. "hash" is the hash of the values: pass it as "base" to fields-set, which then saves only if the values did not change since.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'postId' => ['type' => 'integer', 'description' => 'Post ID.'],
                    'termId' => ['type' => 'integer', 'description' => 'Term ID.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'fields' => ['type' => 'array', 'description' => 'Field definitions from the content model.'],
                    'values' => ['type' => 'object', 'description' => 'Saved values by field key.'],
                    'hash' => ['type' => 'string', 'description' => 'Hash of the values as returned. Pass it as "base" to fields-set.'],
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
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
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

        [$kind, $id] = $target;
        $fields = FieldValues::fields($kind, $id);
        if ($fields === []) {
            return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => __('No content type or taxonomy with fields has this ID.', 'nucleo')]];
        }

        $values = FieldValues::get($kind, $id);

        return ['ok' => true, 'fields' => $fields, 'values' => $values, 'hash' => self::hashOf($values)];
    }

    /**
     * Hash dei valori dei campi: sha1 del JSON dei valori come li restituisce fields-get.
     *
     * @param array<string,mixed> $values
     */
    public static function hashOf(array $values): string
    {
        return sha1((string) wp_json_encode($values));
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
