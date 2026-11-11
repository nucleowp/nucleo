<?php

declare(strict_types=1);

namespace Nucleo\Forms;

defined('ABSPATH') || exit;

/**
 * Azioni dei moduli con effetti fuori dal sito (regola R4.4 della guida): webhook, email a terzi,
 * creazione di contenuti e azioni dei componenti. Chi pubblica il modulo chiede prima il consenso all'utente.
 */
final class ConsequentialActions
{
    /**
     * Azioni consequenziali di un nodo nc/native-form; lista vuota per gli altri nodi.
     * @param array<string,mixed> $node
     * @return list<array{type:string,target:string}>
     */
    public static function ofNode(array $node): array
    {
        if (($node['type'] ?? null) !== 'nc/native-form') {
            return [];
        }
        $found = [];
        $actions = $node['settings']['actions'] ?? [];
        foreach (is_array($actions) ? $actions : [] as $action) {
            $entry = is_array($action) ? self::entry($action) : null;
            if ($entry !== null) {
                $found[] = $entry;
            }
        }

        return $found;
    }

    /**
     * @param array<mixed> $action
     * @return array{type:string,target:string}|null
     */
    private static function entry(array $action): ?array
    {
        $type = $action['type'] ?? null;
        $text = static fn (string $key): string => is_string($action[$key] ?? null) ? $action[$key] : '';

        return match ($type) {
            'webhook' => ['type' => 'webhook', 'target' => $text('url')],
            'create-post' => ['type' => 'create-post', 'target' => $text('postType')],
            'component' => ['type' => 'component', 'target' => $text('name')],
            // L'email all'amministratore resta nel sito (anche senza "to", che vale admin); ogni altro destinatario è un terzo.
            'email' => in_array($text('to'), ['', 'admin'], true) ? null : ['type' => 'email', 'target' => $text('to')],
            default => null,
        };
    }
}
