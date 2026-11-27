<?php

declare(strict_types=1);

namespace Nucleo\Integrations\Forms;

defined('ABSPATH') || exit;

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
     * Token (shortcode o nome di blocco) del plugin: la regola non si applica dove uno di essi è in uso.
     *
     * @return array<string, list<string>> plugin => token
     */
    public static function uses(): array
    {
        return [
            'cf7' => ['contact-form-7', 'contact-form'],
            'gravity' => ['gravityform', 'gravityforms', 'gravityforms/form'],
            'wpforms' => ['wpforms', 'wpforms/form-selector'],
            'fluent' => ['fluentform', 'fluentfom/guten-block'],
        ];
    }

    /**
     * Plugin di moduli caricati in questa richiesta.
     *
     * @return list<string> chiavi di handles()
     */
    public static function active(): array
    {
        $active = [];
        if (defined('WPCF7_VERSION')) {
            $active[] = 'cf7';
        }
        if (class_exists('GFForms')) {
            $active[] = 'gravity';
        }
        if (function_exists('wpforms')) {
            $active[] = 'wpforms';
        }
        if (defined('FLUENTFORM_VERSION')) {
            $active[] = 'fluent';
        }

        return $active;
    }

    /**
     * Dequeue degli asset di ogni plugin su tutte le pagine, tranne quelle che usano un modulo di quel plugin.
     *
     * @param callable(string): list<string> $pagesUsing restituisce le chiavi 'page:ID' per una chiave 'form:<plugin>'
     * @param list<string>|null $plugins limita le regole a queste chiavi di handles(); null = tutti
     * @return list<array{action: string, handle: string, templates: list<string>, except: list<string>, uses: list<string>}>
     */
    public static function rules(callable $pagesUsing, ?array $plugins = null): array
    {
        $rules = [];
        $uses = self::uses();
        foreach (self::handles() as $plugin => $handles) {
            if ($plugins !== null && !in_array($plugin, $plugins, true)) {
                continue;
            }
            $except = $pagesUsing('form:' . $plugin);
            foreach ($handles as $handle) {
                $rules[] = [
                    'action' => 'dequeue',
                    'handle' => $handle,
                    'templates' => ['*'],
                    'except' => $except,
                    'uses' => $uses[$plugin],
                ];
            }
        }

        return $rules;
    }
}
