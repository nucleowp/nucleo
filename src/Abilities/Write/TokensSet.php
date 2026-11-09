<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Compiler\TokensPublisher;
use Nucleo\Quality\Rules\ContrastRule;
use Nucleo\Services;
use Nucleo\Storage\AuditLog;

/** nucleo/tokens-set: modifica i token di design e ripubblica il CSS. */
final class TokensSet implements AbilityDefinition
{
    /** Documenti pubblicati controllati per il contrasto. */
    private const CONTRAST_DOCS = 50;

    public function name(): string
    {
        return 'nucleo/tokens-set';
    }

    public function args(): array
    {
        return [
            'label' => __('Edit tokens', 'nucleo'),
            'description' => 'Edits the design tokens (groups color, space, text, font, radius, shadow, container). Only the values to change are sent; they are merged with the saved tokens and the defaults; null removes a key (back to the default, or gone if the key is new). A new key extends a scale: {"space": {"20": 6}} creates $space.20 (keys: lowercase letters, digits and -, at most 48 per group). Units: space and text in rem, text as [minRem, maxRem], container as integer px. Invalid values or unknown groups return invalid_tokens. The result lists the contrast problems the new tokens cause in published documents (up to 50). Full rules: nucleo/catalog-get {"topic": "tokens"}.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['tokens'],
                'properties' => [
                    'tokens' => ['type' => 'object', 'description' => 'Token groups, for example {"color": {"primary": "#1d4ed8"}, "space": {"4": 1}, "text": {"xl": [1.25, 1.5]}, "container": {"lg": 1200}}.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'tokens' => ['type' => 'object'],
                    'contrast' => [
                        'type' => 'array',
                        'description' => 'Contrast problems that the new tokens cause in published documents.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'rule' => ['type' => 'string'],
                                'postId' => ['type' => 'integer', 'minimum' => 1],
                                'node' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'error' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                            'path' => ['type' => 'string'],
                            'received' => ['type' => 'string'],
                            'allowed' => ['type' => 'string'],
                            'fix' => ['type' => 'string'],
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
        $tokens = $input['tokens'] ?? null;
        if (!is_array($tokens)) {
            return new \WP_Error('invalid_input', __('The "tokens" field must be an object.', 'nucleo'), [
                'path' => 'tokens',
                'received' => get_debug_type($tokens),
                'allowed' => 'object of token groups',
                'fix' => 'Send tokens as an object of groups, for example {"color": {"primary": "#1d4ed8"}}.',
            ]);
        }

        // Si inviano solo i valori da cambiare: si uniscono a quelli salvati, gruppo per gruppo e chiave per chiave.
        $stored = get_option('nucleo_tokens', []);
        $merged = is_array($stored) ? $stored : [];
        // Una chiave con valore null si toglie: torna al default o, se è una chiave nuova, sparisce.
        foreach ($tokens as $group => $values) {
            $current = is_array($merged[$group] ?? null) ? $merged[$group] : [];
            if (!is_array($values)) {
                $merged[$group] = $values;
                continue;
            }
            foreach ($values as $key => $value) {
                if ($value === null) {
                    unset($current[$key]);
                } else {
                    $current[$key] = $value;
                }
            }
            $merged[$group] = $current;
        }

        $before = self::contrastProblems();
        $set = TokensPublisher::update($merged);
        if ($set instanceof \WP_Error) {
            $code = (string) $set->get_error_code();
            AuditLog::record('tokens-set', 0, false, $code);
            $data = is_array($set->get_error_data()) ? $set->get_error_data() : [];

            return ['ok' => false, 'error' => ['code' => $code, 'message' => $set->get_error_message()] + self::errorDetail($data)];
        }

        AuditLog::record('tokens-set', 0, true, 'ok');
        // I documenti si ricompongono con i token appena salvati; contano solo i problemi che i nuovi token causano.
        Services::reset();
        $after = self::contrastProblems();

        return ['ok' => true, 'tokens' => $set->toArray(), 'contrast' => array_values(array_diff_key($after, $before))];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_theme_options');
    }

    /**
     * Problemi di contrasto dei documenti pubblicati con i token correnti, per regola, documento e nodo.
     * @return array<string,array{rule:string,postId:int,node:string}>
     */
    private static function contrastProblems(): array
    {
        $ids = get_posts([
            'post_type' => 'any',
            'post_status' => 'publish',
            'meta_key' => '_nucleo_doc',
            'fields' => 'ids',
            'numberposts' => self::CONTRAST_DOCS,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        $rule = new ContrastRule();
        $problems = [];
        foreach ($ids as $postId) {
            $found = Services::documents()->get($postId);
            if ($found === null) {
                continue;
            }
            $doc = Services::documents()->prepare($found['document'], $postId)->doc;
            foreach ($rule->check(Services::composer()->compose(['page:' . $postId => $doc])) as $issue) {
                foreach ($issue->nodes as $node) {
                    $problems[$issue->rule . '|' . $postId . '|' . $node['id']] = ['rule' => $issue->rule, 'postId' => $postId, 'node' => $node['id']];
                }
            }
        }

        return $problems;
    }

    /**
     * Dettagli dell'errore: path, received, allowed quando noti, e una correzione in una frase.
     * @param array<string,mixed> $data
     * @return array{path?:string,received?:string,allowed?:string,fix:string}
     */
    private static function errorDetail(array $data): array
    {
        $detail = [];
        foreach (['path', 'received', 'allowed'] as $key) {
            if (is_string($data[$key] ?? null)) {
                $detail[$key] = $data[$key];
            }
        }
        $detail['fix'] = is_string($data['fix'] ?? null)
            ? $data['fix']
            : 'Check the values against nucleo/catalog-get {"topic": "tokens"} and send them again.';

        return $detail;
    }
}
