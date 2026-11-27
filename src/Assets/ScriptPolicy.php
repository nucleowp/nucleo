<?php

declare(strict_types=1);

namespace Nucleo\Assets;

defined('ABSPATH') || exit;

/**
 * Decide se uno script si può ritardare al primo gesto (strategie interaction e idle).
 * Stessa regola per il runtime, per il report e per gli avvisi di assets-rules-set.
 */
final class ScriptPolicy
{
    /** Un altro script della pagina dipende da lui: girerebbe prima della libreria. */
    public const DEPENDENTS = 'dependents';
    /** Ha codice inline before o after: girerebbe prima dello script. */
    public const INLINE = 'inline';
    /** È nell'elenco NEVER e la regola non ha force. */
    public const PROTECTED = 'protected';
    /** Con il gruppo: un suo dipendente è nell'elenco NEVER, quindi il gruppo non si ritarda. */
    public const PROTECTED_DEPENDENT = 'protected-dependent';

    /**
     * Handle che non si ritardano al gesto senza force. Un * finale vale come prefisso.
     * Misurati in laboratorio: reindirizzamento per lingua, banner di consenso, carrello e cassa, jQuery usato da codice fuori coda.
     */
    public const NEVER = [
        'jquery', 'jquery-core', 'jquery-migrate',
        'nucleo-loader',
        'wpml-browser-redirect',
        'cookie-law-info', 'cmplz-cookiebanner', 'cookie-notice-front',
        'wc-cart', 'wc-checkout', 'wc-cart-block*', 'wc-checkout-block*',
    ];

    /** WordPress registra jquery-migrate senza dipendenze: senza questa voce resterebbe attivo senza jQuery. */
    private const IMPLICIT_DEPS = ['jquery-migrate' => ['jquery-core']];

    public static function isProtected(string $handle): bool
    {
        foreach (self::NEVER as $entry) {
            if (str_ends_with($entry, '*')) {
                if (str_starts_with($handle, substr($entry, 0, -1))) {
                    return true;
                }
            } elseif ($handle === $entry) {
                return true;
            }
        }

        return false;
    }

    /**
     * Motivi nell'ordine: protected, dependents, inline. force toglie solo protected.
     * Un handle assente da $facts si giudica solo sull'elenco NEVER.
     *
     * Con $group il ritardo riguarda lo script e tutti i suoi dipendenti, anche indiretti:
     * dependents e inline non sono motivi, e blocking elenca i dipendenti protetti.
     * Un dipendente protetto è motivo PROTECTED_DEPENDENT solo senza force: force toglie
     * il blocco anche per i dipendenti protetti, come per lo script stesso.
     * group è [handle, ...dependents] se si ritarda, altrimenti [].
     *
     * @param array<string, array{deps?: list<string>, inline?: bool}> $facts script della pagina: handle => fatti
     * @return array{delay: bool, reasons: list<string>, dependents: list<string>, blocking: list<string>, group: list<string>}
     */
    public static function verdict(string $handle, array $facts, bool $force = false, bool $group = false): array
    {
        // Dipendenti per livelli: con $group si seguono anche le catene, senza $group solo il primo livello.
        $reached = [$handle];
        $frontier = [$handle];
        do {
            $found = [];
            foreach ($facts as $other => $fact) {
                $name = (string) $other;
                $deps = [...($fact['deps'] ?? []), ...(self::IMPLICIT_DEPS[$name] ?? [])];
                if (!in_array($name, $reached, true) && array_intersect($deps, $frontier) !== []) {
                    $found[] = $name;
                }
            }
            $reached = array_merge($reached, $found);
            $frontier = $found;
        } while ($group && $found !== []);

        $dependents = array_values(array_filter(
            array_map('strval', array_keys($facts)),
            static fn (string $name): bool => $name !== $handle && in_array($name, $reached, true)
        ));
        $blocking = $group
            ? array_values(array_filter($dependents, static fn (string $name): bool => self::isProtected($name)))
            : [];

        $reasons = [];
        if (self::isProtected($handle) && !$force) {
            $reasons[] = self::PROTECTED;
        }
        if ($group) {
            if ($blocking !== [] && !$force) {
                $reasons[] = self::PROTECTED_DEPENDENT;
            }
        } else {
            if ($dependents !== []) {
                $reasons[] = self::DEPENDENTS;
            }
            if (!empty($facts[$handle]['inline'])) {
                $reasons[] = self::INLINE;
            }
        }

        $delay = $reasons === [];

        return [
            'delay' => $delay,
            'reasons' => $reasons,
            'dependents' => $dependents,
            'blocking' => $blocking,
            'group' => $group && $delay ? [$handle, ...$dependents] : [],
        ];
    }

    /**
     * Gli handle dati più tutte le loro dipendenze, anche indirette. Ordine: prima visita, senza doppioni.
     *
     * @param list<string> $handles
     * @param array<string, list<string>> $deps handle => handle da cui dipende
     * @return list<string>
     */
    public static function closure(array $handles, array $deps): array
    {
        $out = [];
        $visit = function (string $handle) use (&$visit, &$out, $deps): void {
            if (in_array($handle, $out, true)) {
                return;
            }
            $out[] = $handle;
            foreach ($deps[$handle] ?? [] as $dep) {
                $visit($dep);
            }
        };

        foreach ($handles as $handle) {
            $visit($handle);
        }

        return $out;
    }
}
