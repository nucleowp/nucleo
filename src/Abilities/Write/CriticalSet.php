<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Assets\CriticalCss;

/** nucleo/critical-set: salva il critical CSS di un template (chiamata dal CLI o dal servizio). */
final class CriticalSet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/critical-set';
    }

    public function args(): array
    {
        return [
            'label' => __('Critical CSS', 'nucleo'),
            'description' => 'Saves the critical CSS of a template. The CSS is stripped of "</style" and limited to 50 KB.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['template', 'css'],
                'properties' => [
                    'template' => ['type' => 'string', 'description' => 'Template type, for example home, page, single, product.'],
                    'css' => ['type' => 'string', 'description' => 'Critical (above the fold) CSS of the template.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'bytes' => ['type' => 'integer'],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    public function execute(array $input): array|\WP_Error
    {
        $template = $input['template'] ?? null;
        $css = $input['css'] ?? null;
        if (!is_string($template) || $template === '' || !is_string($css)) {
            return new \WP_Error('invalid_input', __('"template" and "css" must be strings.', 'nucleo'));
        }

        try {
            CriticalCss::set($template, $css);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_critical_css', $e->getMessage());
        }

        return ['ok' => true, 'bytes' => strlen((string) CriticalCss::get($template))];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_theme_options');
    }
}
