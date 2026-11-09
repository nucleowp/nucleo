<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/**
 * Stima degli elementi DOM: nodi più tag HTML di apertura in content.html. Un avviso per pagina.
 */
final class DomSize implements Rule
{
    public function __construct(private readonly ?int $budget = null)
    {
    }

    public function id(): string
    {
        return 'perf.dom.size';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $estimate = count($page->nodes);
        foreach ($page->nodes as $entry) {
            $html = $entry['node']['content']['html'] ?? '';
            if (is_string($html)) {
                $estimate += preg_match_all('/<[a-zA-Z][^>]*>/', $html);
            }
        }

        $budget = $this->budget ?? \Nucleo\Quality\Budgets::get('dom');
        if ($estimate <= $budget) {
            return [];
        }

        return [new Issue(
            $this->id(),
            $this->severity(),
            sprintf(
                /* translators: 1: estimated DOM elements, 2: budget. */
                __('The page has an estimated %1$d DOM elements, above the budget of %2$d.', 'nucleo'),
                $estimate,
                $budget,
            ),
        )];
    }
}
