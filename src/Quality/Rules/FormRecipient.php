<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

defined('ABSPATH') || exit;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/**
 * Destinatario delle email di un modulo: admin, indirizzo email valido o field:<campo>. Avviso che non blocca la pubblicazione.
 */
final class FormRecipient implements Rule
{
    private const FIELD_REF = '/\Afield:[a-z][a-z0-9_]{0,31}\z/D';

    public function id(): string
    {
        return 'form.recipient.invalid';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->forms as $form) {
            foreach ($form['actions'] as $action) {
                if ($action['type'] !== 'email' || self::allowed($action['target'])) {
                    continue;
                }
                $issues[] = new Issue(
                    $this->id(),
                    $this->severity(),
                    sprintf(
                        /* translators: %s: the recipient found in the email action. */
                        __('The email recipient "%s" is not valid: use admin, a valid email address or field:<name> of an email field.', 'nucleo'),
                        $action['target'],
                    ),
                    [['doc' => $form['doc'], 'id' => $form['id']]],
                    [],
                    false,
                );
            }
        }

        return $issues;
    }

    /** Destinatario ammesso: vuoto o admin, indirizzo email valido, o riferimento field:<nome>. */
    private static function allowed(string $target): bool
    {
        if ($target === '' || $target === 'admin') {
            return true;
        }

        // filter_var invece di is_email: la regola gira anche nei test unitari, senza WordPress.
        return preg_match(self::FIELD_REF, $target) === 1 || filter_var($target, FILTER_VALIDATE_EMAIL) !== false;
    }
}
