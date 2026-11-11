<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Storage\AuditLog;
use Nucleo\Tokens\FontImporter;

/** nucleo/fonts-import: scarica una famiglia Google Fonts in locale e ripubblica i token. */
final class FontsImport implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/fonts-import';
    }

    public function args(): array
    {
        return [
            'label' => __('Import font', 'nucleo'),
            'description' => 'Downloads a Google Fonts family locally (woff2 in uploads) and makes it available to the tokens. Replaces the same family already imported. Network or format errors return ok false.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['family', 'weights'],
                'properties' => [
                    'family' => ['type' => 'string', 'description' => 'Family name, for example "Inter".'],
                    'weights' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Weights to download: multiples of 100 between 100 and 900, at most 9.',
                    ],
                    'italic' => ['type' => 'boolean', 'description' => 'Downloads the italic variants instead of the regular ones.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'faces' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'family' => ['type' => 'string'],
                                'weight' => ['type' => 'integer'],
                                'style' => ['type' => 'string'],
                                'url' => ['type' => 'string'],
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

    public function execute(array $input): array|\WP_Error
    {
        $family = $input['family'] ?? null;
        $weights = $input['weights'] ?? null;
        $italic = $input['italic'] ?? false;
        if (!is_string($family) || !is_array($weights) || !is_bool($italic)) {
            return new \WP_Error('invalid_input', __('"family" (text) and "weights" (list of numbers) are required; "italic" must be a boolean.', 'nucleo'));
        }

        $faces = (new FontImporter())->import($family, array_values($weights), $italic);
        if ($faces instanceof \WP_Error) {
            $code = (string) $faces->get_error_code();
            AuditLog::record('fonts-import', 0, false, $code);

            return ['ok' => false, 'error' => ['code' => $code, 'message' => $faces->get_error_message()]];
        }

        AuditLog::record('fonts-import', 0, true, 'ok');

        // Il percorso del file sul server non esce dalla ability: solo l'URL pubblico.
        return [
            'ok' => true,
            'faces' => array_map(
                static fn (array $face): array => [
                    'family' => $face['family'],
                    'weight' => $face['weight'],
                    'style' => $face['style'],
                    'url' => $face['url'],
                ],
                $faces,
            ),
        ];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_theme_options');
    }
}
