<?php

declare(strict_types=1);

namespace Nucleo\Integrations\Forms;

/**
 * Handle degli asset dei plugin di moduli e regole di dequeue, da collegare a RulesPublisher.
 */
final class FormAssets
{
    /**
     * @return array<string, list<string>> plugin => handle degli asset
     */
    public static function handles(): array
    {
        return [
            'cf7' => ['contact-form-7', 'swv'],
            'gravity' => ['gform_gravityforms', 'gform_json'],
            'wpforms' => ['wpforms-full'],
            'fluent' => ['fluent-form-styles', 'fluent-form-submission'],
        ];
    }

    /**
     * Dequeue degli asset di ogni plugin su tutte le pagine, tranne quelle che usano un modulo di quel plugin.
     *
     * @param callable(string): list<string> $pagesUsing restituisce le chiavi 'page:ID' per una chiave 'form:<plugin>'
     * @return list<array{action: string, handle: string, templates: list<string>, except: list<string>}>
     */
    public static function rules(callable $pagesUsing): array
    {
        $rules = [];
        foreach (self::handles() as $plugin => $handles) {
            $except = $pagesUsing('form:' . $plugin);
            foreach ($handles as $handle) {
                $rules[] = [
                    'action' => 'dequeue',
                    'handle' => $handle,
                    'templates' => ['*'],
                    'except' => $except,
                ];
            }
        }

        return $rules;
    }
}
