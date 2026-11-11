<?php

declare(strict_types=1);

namespace Nucleo\Assets;

defined('ABSPATH') || exit;

/**
 * Applica la tabella delle regole alla coda degli script di una pagina.
 */
final class RulePlanner
{
    /**
     * Precedenza: chiave d'oggetto, poi template, poi '*'.
     * Una voce con except che contiene il template o l'oggetto non si applica
     * in quell'ambito e lascia il passo al livello successivo.
     *
     * Un dequeue che rimuoverebbe un handle da cui dipende un altro handle in coda
     * (non rimosso a sua volta) finisce in blocked.
     *
     * @param list<string> $queue
     * @param array<string, list<string>> $deps handle => handle da cui dipende
     * @param array<string, array<string, array<string, mixed>>> $table
     * @return array{dequeue: list<string>, defer: array<string, string>, replace: array<string, string>, blocked: list<string>}
     */
    public static function plan(string $template, string $objectKey, array $queue, array $deps, array $table): array
    {
        $scopes = array_values(array_unique([$objectKey, $template, '*']));
        $candidates = [];
        $defer = [];
        $replace = [];

        foreach ($queue as $handle) {
            $rule = self::lookup($handle, $scopes, $template, $objectKey, $table);
            if ($rule === null) {
                continue;
            }
            if ($rule['action'] === 'dequeue') {
                $candidates[] = $handle;
            } elseif ($rule['action'] === 'defer') {
                $defer[$handle] = (string) $rule['strategy'];
            } elseif ($rule['action'] === 'replace') {
                $replace[$handle] = (string) $rule['with'];
            }
        }

        // Un handle resta in coda se un altro handle ancora in coda dipende da lui.
        $final = array_flip($candidates);
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($queue as $handle) {
                if (isset($final[$handle])) {
                    continue;
                }
                foreach ($deps[$handle] ?? [] as $dep) {
                    if (isset($final[$dep])) {
                        unset($final[$dep]);
                        $changed = true;
                    }
                }
            }
        }

        $dequeue = [];
        $blocked = [];
        foreach ($candidates as $handle) {
            if (isset($final[$handle])) {
                $dequeue[] = $handle;
            } else {
                $blocked[] = $handle;
            }
        }

        return [
            'dequeue' => $dequeue,
            'defer' => $defer,
            'replace' => $replace,
            'blocked' => $blocked,
        ];
    }

    /**
     * @param list<string> $scopes
     * @param array<string, array<string, array<string, mixed>>> $table
     * @return array<string, mixed>|null
     */
    private static function lookup(string $handle, array $scopes, string $template, string $objectKey, array $table): ?array
    {
        foreach ($scopes as $scope) {
            $rule = $table[$scope][$handle] ?? null;
            if ($rule === null) {
                continue;
            }
            if (in_array($template, $rule['except'], true) || in_array($objectKey, $rule['except'], true)) {
                continue;
            }

            return $rule;
        }

        return null;
    }
}
