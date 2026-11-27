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
     * Una voce con uses si salta come una voce con except quando $late è true
     * o quando uno dei suoi token è in $used (shortcode o blocchi in uso nella pagina).
     *
     * Una voce keep lascia il handle com'è e ferma la ricerca: una keep su page:7
     * nasconde un dequeue su '*'.
     *
     * Un dequeue che rimuoverebbe un handle da cui dipende un altro handle in coda
     * (non rimosso a sua volta) finisce in blocked.
     *
     * @param list<string> $queue
     * @param array<string, list<string>> $deps handle => handle da cui dipende
     * @param array<string, array<string, array<string, mixed>>> $table
     * @param list<string> $used token in uso nella pagina
     * group elenca i handle con regola defer e group; replay mappa handle => selettore CSS della regola defer.
     *
     * @return array{dequeue: list<string>, defer: array<string, string>, replace: array<string, string>, blocked: list<string>, keep: list<string>, force: list<string>, group: list<string>, replay: array<string, string>, uses: array<string, list<string>>}
     */
    public static function plan(string $template, string $objectKey, array $queue, array $deps, array $table, array $used = [], bool $late = false): array
    {
        $scopes = array_values(array_unique([$objectKey, $template, '*']));
        $candidates = [];
        $candidateUses = [];
        $defer = [];
        $replace = [];
        $keep = [];
        $force = [];
        $group = [];
        $replay = [];

        foreach ($queue as $handle) {
            $rule = self::lookup($handle, $scopes, $template, $objectKey, $table, $used, $late);
            if ($rule === null) {
                continue;
            }
            if ($rule['action'] === 'dequeue') {
                $candidates[] = $handle;
                if (isset($rule['uses'])) {
                    $candidateUses[$handle] = $rule['uses'];
                }
            } elseif ($rule['action'] === 'defer') {
                $defer[$handle] = (string) $rule['strategy'];
                if (($rule['force'] ?? null) === true) {
                    $force[] = $handle;
                }
                if (($rule['group'] ?? null) === true) {
                    $group[] = $handle;
                }
                if (isset($rule['replay'])) {
                    $replay[$handle] = (string) $rule['replay'];
                }
            } elseif ($rule['action'] === 'replace') {
                $replace[$handle] = (string) $rule['with'];
            } elseif ($rule['action'] === 'keep') {
                $keep[] = $handle;
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
        $uses = [];
        foreach ($candidates as $handle) {
            if (isset($final[$handle])) {
                $dequeue[] = $handle;
                if (isset($candidateUses[$handle])) {
                    $uses[$handle] = $candidateUses[$handle];
                }
            } else {
                $blocked[] = $handle;
            }
        }

        return [
            'dequeue' => $dequeue,
            'defer' => $defer,
            'replace' => $replace,
            'blocked' => $blocked,
            'keep' => $keep,
            'force' => $force,
            'group' => $group,
            'replay' => $replay,
            'uses' => $uses,
        ];
    }

    /**
     * @param list<string> $scopes
     * @param list<string> $used
     * @param array<string, array<string, array<string, mixed>>> $table
     * @return array<string, mixed>|null
     */
    private static function lookup(string $handle, array $scopes, string $template, string $objectKey, array $table, array $used, bool $late): ?array
    {
        foreach ($scopes as $scope) {
            $rule = $table[$scope][$handle] ?? null;
            if ($rule === null) {
                continue;
            }
            if (in_array($template, $rule['except'], true) || in_array($objectKey, $rule['except'], true)) {
                continue;
            }
            if (isset($rule['uses']) && ($late || array_intersect($rule['uses'], $used) !== [])) {
                continue;
            }

            return $rule;
        }

        return null;
    }
}
