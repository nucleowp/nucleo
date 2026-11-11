<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Assets\CriticalCss;
use Nucleo\Assets\CriticalSubset;
use Nucleo\Assets\LocalStylesheets;

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
            'description' => 'Saves the critical CSS of a template from the rules the site already loads. The css input is read only as a list of selectors: for each rule Nucleo copies the matching rule, with the same media query, from the local stylesheets of the theme, the active plugins, WordPress and Nucleo. Rules found in no local stylesheet are dropped and counted in dropped. Limit: 50 KB.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['template', 'css'],
                'properties' => [
                    'template' => ['type' => 'string', 'description' => 'Template type, for example home, page, single, product.'],
                    'css' => ['type' => 'string', 'description' => 'Above the fold rules of the template, as the browser reports them: only their selectors and media queries are used.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'bytes' => ['type' => 'integer'],
                    'kept' => ['type' => 'integer'],
                    'dropped' => ['type' => 'integer'],
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

        // Il plugin non salva il CSS ricevuto: salva le regole corrispondenti dei fogli di stile locali.
        $subset = CriticalSubset::extract($css, LocalStylesheets::all());
        try {
            CriticalCss::set($template, $subset['css']);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_critical_css', $e->getMessage());
        }

        return [
            'ok' => true,
            'bytes' => strlen((string) CriticalCss::get($template)),
            'kept' => $subset['kept'],
            'dropped' => $subset['dropped'],
        ];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_theme_options');
    }
}
