<?php

declare(strict_types=1);

namespace Nucleo\Schema;

defined('ABSPATH') || exit;

/**
 * Grammatica ammessa per i valori CSS: solo i token elencati e le funzioni in FUNCTIONS.
 * Accetta o rifiuta, non modifica l'input. url(), attr() e le altre funzioni restano fuori.
 */
final class CssValueGrammar
{
    /** Funzioni CSS ammesse, in minuscolo (il confronto ignora il caso). */
    private const FUNCTIONS = [
        'var', 'calc', 'min', 'max', 'clamp', 'rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch',
        'color', 'color-mix', 'linear-gradient', 'radial-gradient', 'conic-gradient', 'repeating-linear-gradient',
        'repeating-radial-gradient', 'repeating-conic-gradient', 'translate', 'translatex', 'translatey', 'translatez',
        'translate3d', 'scale', 'scalex', 'scaley', 'scalez', 'scale3d', 'rotate', 'rotatex', 'rotatey', 'rotatez',
        'rotate3d', 'skew', 'skewx', 'skewy', 'matrix', 'matrix3d', 'perspective', 'cubic-bezier', 'steps', 'repeat',
        'minmax', 'fit-content', 'blur', 'brightness', 'contrast', 'drop-shadow', 'grayscale', 'hue-rotate', 'invert',
        'opacity', 'saturate', 'sepia', 'inset', 'circle', 'ellipse', 'polygon', 'env', 'counter', 'counters',
    ];

    /** Profondità massima di funzioni annidate: oltre questo limite il valore è rifiutato. */
    private const MAX_DEPTH = 16;

    // Gli spazi bianchi sono gli stessi usati dal trim. Ogni regex è ancorata con \G alla posizione corrente.
    private const WS = '/\G[ \t\n\r\f]+/';
    private const STRING = '/\G(?:\'[^\'\\\\\n]*\'|"[^"\\\\\n]*")/';
    // Il lookahead impedisce di spezzare un token lungo (#fffff non è #ffff seguito da f).
    private const HEX = '/\G#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{4}|[0-9a-fA-F]{3})(?![0-9a-zA-Z_])/';
    private const NUMBER = '/\G[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?(?:%|[a-zA-Z]{1,6})?(?![0-9a-zA-Z_%.])/';
    private const FUNCTION = '/\G-{0,2}[a-zA-Z_][a-zA-Z0-9_-]*\(/';
    private const IDENT = '/\G-{0,2}[a-zA-Z_][a-zA-Z0-9_-]*/';
    private const TOKEN_REF = '/\G\$[a-zA-Z][a-zA-Z0-9_.-]*/';
    private const PUNCT = '/\G[,\/+\-*]/';

    /** true se il valore CSS usa solo la sintassi ammessa. Dopo il trim una stringa vuota non lo è. */
    public static function accepts(string $value): bool
    {
        $v = trim($value, " \t\n\r\f");
        if ($v === '') {
            return false;
        }

        $pos = 0;

        return self::sequence($v, $pos, false, 0);
    }

    /**
     * Consuma i token da $pos fino alla fine del testo o, dentro una funzione, fino alla ")" che la chiude.
     * Una ")" fuori da una funzione, o una funzione non chiusa, rendono il valore non valido.
     */
    private static function sequence(string $v, int &$pos, bool $inFunction, int $depth): bool
    {
        while ($pos < strlen($v)) {
            if ($v[$pos] === ')') {
                if (!$inFunction) {
                    return false;
                }
                $pos++;

                return true;
            }

            if (
                self::take($v, $pos, self::WS) !== null
                || self::take($v, $pos, self::STRING) !== null
                || self::take($v, $pos, self::HEX) !== null
                || self::take($v, $pos, self::NUMBER) !== null
            ) {
                continue;
            }

            // Il nome della funzione va controllato prima di IDENT, che lo consumerebbe senza la "(".
            $call = self::take($v, $pos, self::FUNCTION);
            if ($call !== null) {
                $name = strtolower(substr($call, 0, -1));
                if (
                    !in_array($name, self::FUNCTIONS, true)
                    || $depth >= self::MAX_DEPTH
                    || !self::sequence($v, $pos, true, $depth + 1)
                ) {
                    return false;
                }
                continue;
            }

            if (
                self::take($v, $pos, self::IDENT) !== null
                || self::take($v, $pos, self::TOKEN_REF) !== null
                || self::take($v, $pos, self::PUNCT) !== null
            ) {
                continue;
            }

            return false;
        }

        return !$inFunction;
    }

    /** Consuma il token che inizia in $pos se la regex lo riconosce; restituisce il testo consumato o null. */
    private static function take(string $v, int &$pos, string $regex): ?string
    {
        if (preg_match($regex, $v, $m, 0, $pos) !== 1) {
            return null;
        }
        $pos += strlen($m[0]);

        return $m[0];
    }
}
