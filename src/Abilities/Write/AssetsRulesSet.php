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
            'description' => 'Replaces the asset rules and republishes the table. Actions: dequeue (do not load), defer (scripts; strategy defer, async, interaction or idle: the last two load the script at the first user gesture or when the browser is idle), replace (swap for the registered handle in "with"), keep (always load, even where a built-in rule would remove it). "templates" lists the page types (empty = all), "except" the ones left out, "uses" the shortcode tags or block names that keep the file where they are rendered. A script delayed to interaction or idle falls back to a normal defer when other scripts depend on it, it has inline code or it is on the never-delay list ("force": true overrides only the list). "group": true delays the script together with the scripts that depend on it and their inline code; it falls back to a normal defer only when one of those dependents is on the never-delay list. "replay" is a CSS selector: a first click inside it, lost while the scripts load, is repeated. A script printed outside the WordPress queue has no handle: a defer rule can name it with "src" (a part of the script URL) or "code" (a part of the inline code) in place of "handle", with strategy interaction or idle only. Consent, payment and captcha scripts stay as they are unless the rule has "force": true. Delaying analytics, tag managers or advertising pixels loses the visits that end without an interaction: add such a rule only when the user asked for it. Read "warnings" in the answer: they name the ignored rules and the delays that will not apply. Check a change with nucleo/assets-audit and nucleo/assets-report when available.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['rules'],
                'properties' => [
                    'rules' => ['type' => 'array', 'description' => 'For example {"action":"dequeue","handle":"contact-form-7","templates":["page"]}, {"action":"defer","handle":"chat-widget","strategy":"interaction"} or {"action":"defer","handle":"chat-widget","strategy":"interaction","group":true,"replay":"#chat-button"}. Outside the queue: {"action":"defer","src":"widget.example.com/chat.js","strategy":"interaction"} or {"action":"defer","code":"initChat(","strategy":"idle"}.'],
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
