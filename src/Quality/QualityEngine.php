<?php

declare(strict_types=1);

namespace Nucleo\Quality;

defined('ABSPATH') || exit;

use Nucleo\Quality\Rules\TokenLiteral;
use Nucleo\Tokens\TokenMatcher;
use Nucleo\Tokens\TokenSet;

final class QualityEngine
{
    /**
     * @param list<Rule> $rules
     * @param list<string> $nonForcible regole che non si possono riconoscere per pubblicare
     */
    public function __construct(
        private readonly array $rules,
        private readonly array $nonForcible = [],
    ) {
    }

    /**
     * Id delle regole che il motore tiene, in ordine.
     *
     * @return list<string>
     */
    public function ruleIds(): array
    {
        return array_values(array_map(static fn (Rule $rule): string => $rule->id(), $this->rules));
    }

    /**
     * Regole caricate con la loro gravità, nell'ordine dei RuleSets.
     *
     * @return list<array{id:string,severity:string}>
     */
    public function rules(): array
    {
        return array_values(array_map(static fn (Rule $rule): array => ['id' => $rule->id(), 'severity' => $rule->severity()->value], $this->rules));
    }

    /** Con i token attivano anche le regole che confrontano i valori con i token (style.token.literal). */
    public static function default(?TokenSet $tokens = null): self
    {
        $rules = self::ruleSets();
        if ($tokens !== null) {
            $rules[] = new TokenLiteral(new TokenMatcher($tokens));
        }

        if (function_exists('apply_filters')) {
            $rules = apply_filters('nucleo/quality/rules', $rules);
        }

        return new self(
            array_values(array_filter(
                is_array($rules) ? $rules : [],
                static fn (mixed $rule): bool => $rule instanceof Rule,
            )),
            self::nonForcible(),
        );
    }

    /**
     * Regole di tutti i file RuleSets/*.php, in ordine di nome file; ogni file restituisce una lista di Rule.
     *
     * @return list<Rule>
     */
    private static function ruleSets(): array
    {
        $files = glob(__DIR__ . '/RuleSets/*.php');
        $files = is_array($files) ? $files : [];
        sort($files);

        $rules = [];
        foreach ($files as $file) {
            $set = require $file;
            if (is_array($set)) {
                array_push($rules, ...$set);
            }
        }

        return $rules;
    }

    /**
     * Id delle regole non riconoscibili, dall'opzione nucleo_quality.
     *
     * @return list<string>
     */
    private static function nonForcible(): array
    {
        $options = function_exists('get_option') ? get_option('nucleo_quality', []) : [];
        $ids = is_array($options) && is_array($options['nonForcible'] ?? null) ? $options['nonForcible'] : [];

        $out = [];
        foreach ($ids as $id) {
            if (is_string($id)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /** @param list<array{rule:string,node:string,reason:string}> $acks riconoscimenti forniti dall'AI */
    public function check(ComposedPage $page, array $acks = []): QualityReport
    {
        $open = [];
        $acknowledged = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->check($page) as $issue) {
                if ($this->isAcknowledged($issue, $acks)) {
                    $acknowledged[] = $issue;
                } else {
                    $open[] = $issue;
                }
            }
        }

        return new QualityReport($open, $acknowledged);
    }

    /** @param list<mixed> $acks */
    private function isAcknowledged(Issue $issue, array $acks): bool
    {
        if (in_array($issue->rule, $this->nonForcible, true)) {
            return false;
        }

        $ids = array_column($issue->nodes, 'id');
        foreach ($acks as $ack) {
            if (!is_array($ack)) {
                continue;
            }
            $rule = $ack['rule'] ?? null;
            $node = $ack['node'] ?? null;
            $reason = $ack['reason'] ?? null;
            if (
                $rule === $issue->rule
                && is_string($node) && in_array($node, $ids, true)
                && is_string($reason) && trim($reason) !== ''
            ) {
                return true;
            }
        }

        return false;
    }
}
