<?php

declare(strict_types=1);

namespace Nucleo\Tokens;

use Nucleo\Css\PartMap;
use Nucleo\Css\PropertyMap;

/**
 * Trova il token il cui valore coincide con quello dato, per proprietà CSS.
 * Il confronto è per valore intero: nessuna corrispondenza tra parti di un valore composto.
 */
final class TokenMatcher
{
    /** Proprietà per gruppo di token. */
    private const GROUPS = [
        'color' => [
            'color', 'background', 'accentColor', 'itemColor', 'itemColorHover', 'itemColorActive',
            'itemBackground', 'itemBackgroundHover', 'itemBackgroundActive',
            'submenuBackground', 'panelBackground', ...PartMap::TOKENS['color'],
        ],
        'space' => ['padding', 'margin', 'gap', 'itemPadding', 'itemGap', ...PartMap::TOKENS['space']],
        'radius' => ['borderRadius', 'itemRadius', ...PartMap::TOKENS['radius']],
        'shadow' => ['boxShadow'],
        'font' => ['fontFamily', ...PartMap::TOKENS['font']],
    ];
    private const ROOT_PX = 16;

    public function __construct(private readonly TokenSet $tokens)
    {
    }

    /**
     * Proprietà per gruppo di token. cli/src/use-tokens.js tiene lo stesso elenco: TokenParityTest li confronta
     * attraverso tests/Fixtures/tokens/match-cases.json.
     * @return array<string,list<string>>
     */
    public static function groups(): array
    {
        return self::GROUPS;
    }

    /** Gruppo di token della proprietà, o null se la proprietà non ha token. */
    public static function group(string $prop): ?string
    {
        // Le chiavi di stato ("colorHover") usano i token della chiave base.
        $prop = PropertyMap::baseKey($prop);
        foreach (self::GROUPS as $group => $props) {
            if (in_array($prop, $props, true)) {
                return $group;
            }
        }

        return null;
    }

    /** "$gruppo.chiave" del primo token con lo stesso valore, o null. */
    public function match(string $prop, string $value): ?string
    {
        $group = self::group($prop);
        $wanted = self::key($value);
        if ($group === null || $wanted === null) {
            return null;
        }

        foreach ($this->tokens->toArray()[$group] ?? [] as $key => $tokenValue) {
            if (self::key($tokenValue) === $wanted) {
                return "\$$group.$key";
            }
        }

        return null;
    }

    /** Chiave di confronto normalizzata; null se il valore non è confrontabile (zero, riferimenti, valori multipli). */
    private static function key(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return self::remKey((float) $value);
        }
        if (!is_string($value)) {
            return null;
        }

        $v = strtolower(trim($value));
        $v = preg_replace('/\s+/', ' ', $v) ?? '';
        $v = preg_replace('/\s*,\s*/', ',', $v) ?? '';
        $v = preg_replace('/\(\s+/', '(', $v) ?? '';
        $v = preg_replace('/\s+\)/', ')', $v) ?? '';

        if ($v === '' || $v[0] === '$' || $v === '0') {
            return null;
        }
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $v, $m) === 1) {
            return "#{$m[1]}{$m[1]}{$m[2]}{$m[2]}{$m[3]}{$m[3]}";
        }
        if (preg_match('/^(-?\d*\.?\d+)(px|rem)$/', $v, $m) === 1) {
            $rem = (float) $m[1] / ($m[2] === 'px' ? self::ROOT_PX : 1);

            return self::remKey($rem);
        }

        return $v;
    }

    /** Lunghezze confrontate in rem; zero escluso. */
    private static function remKey(float $rem): ?string
    {
        if ($rem == 0.0) {
            return null;
        }

        return 'rem:' . round($rem, 6);
    }
}
