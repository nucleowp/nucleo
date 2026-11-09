<?php

declare(strict_types=1);

namespace Nucleo\Quality;

use Nucleo\Guide\RuleCodes;

final class QualityReport
{
    /**
     * @param list<Issue> $issues problemi aperti
     * @param list<Issue> $acknowledged problemi riconosciuti dall'AI con motivazione
     */
    public function __construct(
        public readonly array $issues,
        public readonly array $acknowledged = [],
    ) {
    }

    /**
     * Toglie i problemi che riguardano solo i layout composti attorno alla pagina: i layout si controllano al loro
     * salvataggio. Restano quelli senza nodi (struttura della pagina) e quelli con almeno un nodo della pagina.
     */
    public function withoutLayoutIssues(): self
    {
        $own = static function (Issue $issue): bool {
            if ($issue->nodes === []) {
                return true;
            }
            foreach ($issue->nodes as $node) {
                if (!str_starts_with($node['doc'], 'layout:')) {
                    return true;
                }
            }

            return false;
        };

        return new self(array_values(array_filter($this->issues, $own)), array_values(array_filter($this->acknowledged, $own)));
    }

    /** Vero se esiste almeno un errore, o un avviso che blocca la pubblicazione, non riconosciuto. */
    public function blocking(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === Severity::Error || $issue->publishBlocking) {
                return true;
            }
        }

        return false;
    }

    /** @return array{quality: array<string,mixed>} */
    public function toArray(): array
    {
        return [
            'quality' => [
                'score' => [
                    'seo' => $this->score('seo'),
                    'a11y' => $this->score('a11y'),
                    'perf' => $this->score('perf'),
                ],
                'blocking' => $this->blocking(),
                'issues' => array_map(self::issueArray(...), $this->issues),
            ],
        ];
    }

    /**
     * Forma pubblica di un problema. guide (codice della regola della guida) e blocksPublish compaiono solo quando servono.
     * @return array<string,mixed>
     */
    private static function issueArray(Issue $issue): array
    {
        $out = [
            'rule' => $issue->rule,
            'severity' => $issue->severity->value,
            'nodes' => $issue->nodes,
            'message' => $issue->message,
            'fix' => $issue->fix,
        ];
        $guide = RuleCodes::forQuality($issue->rule);
        if ($guide !== null) {
            $out['guide'] = $guide;
        }
        if ($issue->publishBlocking && $issue->severity !== Severity::Error) {
            $out['blocksPublish'] = true;
        }

        return $out;
    }

    /** 100 meno 20 per ogni errore e 5 per ogni avviso, sui problemi del prefisso (seo, a11y, perf); minimo 0. */
    private function score(string $prefix): int
    {
        $score = 100;
        foreach ($this->issues as $issue) {
            if (!str_starts_with($issue->rule, $prefix . '.')) {
                continue;
            }
            $score -= match ($issue->severity) {
                Severity::Error => 20,
                Severity::Warning => 5,
                Severity::Info => 0,
            };
        }

        return max(0, $score);
    }
}
