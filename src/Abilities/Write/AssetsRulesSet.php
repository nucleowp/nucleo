<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Assets\RulesPublisher;
use Nucleo\Storage\AuditLog;

/** nucleo/assets-rules-set: salva le regole asset dell'utente e ripubblica la tabella. */
final class AssetsRulesSet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/assets-rules-set';
    }

    public function args(): array
    {
        return [
            'label' => __('Set asset rules', 'nucleo'),
            'description' => 'Replaces the asset deduplication rules (dequeue, defer, replace) and republishes the table. Invalid rules are ignored.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['rules'],
                'properties' => [
                    'rules' => ['type' => 'array', 'description' => 'For example {"action":"dequeue","handle":"contact-form-7","templates":["page"]}.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'count' => ['type' => 'integer'],
                    'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
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
        $rules = $input['rules'] ?? null;
        if (!is_array($rules) || !array_is_list($rules)) {
            return new \WP_Error('invalid_input', __('The "rules" field must be a list.', 'nucleo'));
        }

        $warnings = RulesPublisher::publish($rules);
        AuditLog::record('assets-rules-set', 0, true, 'ok');

        return ['ok' => true, 'count' => count($rules), 'warnings' => $warnings];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('manage_options');
    }
}
