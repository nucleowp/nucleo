<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

defined('ABSPATH') || exit;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/**
 * R4.4: un modulo con azioni esterne (webhook, email a terzi, nuovi contenuti, azioni dei componenti) chiede il consenso
 * dell'utente prima della pubblicazione. Avviso che blocca la pubblicazione; acknowledge con il consenso nel reason.
 */
final class FormConsequential implements Rule
{
    public function id(): string
    {
        return 'form.action.consequential';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->forms as $form) {
            $list = implode(', ', array_map(
                static fn (array $a): string => $a['target'] === '' ? $a['type'] : $a['type'] . ' ' . $a['target'],
                $form['actions'],
            ));
            $issues[] = new Issue(
                $this->id(),
                Severity::Warning,
                sprintf(
                    /* translators: %s: list of actions, such as "webhook https://example.com/hook". */
                    __('This form has actions with external effects (%s). Ask the user for consent, then publish with acknowledge and the consent in reason, and send a test submission.', 'nucleo'),
                    $list,
                ),
                [['doc' => $form['doc'], 'id' => $form['id']]],
                [],
                true,
            );
        }

        return $issues;
    }
}
