<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Byte di CSS servito alla pagina sopra il budget. Un avviso per pagina. */
final class CssBudget implements Rule
{
    public function __construct(private readonly ?int $budget = null)
    {
    }

    public function id(): string
    {
        return 'perf.css.budget';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        if ($page->weight === null) {
            return [];
        }

        $bytes = $page->weight['css'];
        $budget = $this->budget ?? \Nucleo\Quality\Budgets::get('css');
        if ($bytes <= $budget) {
            return [];
        }

        return [new Issue(
            $this->id(),
            $this->severity(),
            sprintf(
                /* translators: 1: CSS size in KB, 2: budget in KB. */
                __('The CSS served is %1$.1f KB, above the budget of %2$.1f KB.', 'nucleo'),
                $bytes / 1024,
                $budget / 1024,
            ),
        )];
    }
}
