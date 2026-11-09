<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Blocks\Core\IconRenderer;
use Nucleo\Site\SiteIcons;

/** nucleo/icons-set: aggiunge, sostituisce o toglie icone del sito senza toccare le altre. */
final class IconsSet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/icons-set';
    }

    public function args(): array
    {
        return [
            'label' => __('Save site icons', 'nucleo'),
            'description' => 'Adds, replaces or removes site icons for nc/icon and leaves the others as they are. set: name (a-z, 0-9, -) → SVG markup; remove: names. A site icon with the name of a built-in icon (phone, close, chevron-down...) replaces it everywhere, also inside nc/menu and nc/list. Colors become currentColor; scripts, styles, links and unknown elements are removed. At most 300 icons. To import a folder of SVG files or icons of a library (mdi:car, lucide:truck) use the CLI: nucleo icons import <folder>, nucleo icons add <prefix:name>.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'set' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'Icons to add or replace: name → SVG markup.'],
                    'remove' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Names of site icons to remove.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'icons' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Names of the site icons after the change.'],
                    'builtin' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Names of the built-in icons.'],
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

    public function execute(array $input): array
    {
        $set = is_array($input['set'] ?? null) ? $input['set'] : [];
        $remove = is_array($input['remove'] ?? null) ? $input['remove'] : [];
        $result = SiteIcons::update($set, $remove);
        if (is_wp_error($result)) {
            return ['ok' => false, 'error' => ['code' => $result->get_error_code(), 'message' => $result->get_error_message()]];
        }

        return ['ok' => true, 'icons' => array_keys($result), 'builtin' => IconRenderer::names()];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('manage_options');
    }
}
