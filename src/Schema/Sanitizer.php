<?php

declare(strict_types=1);

namespace Nucleo\Schema;

/** Controlli sui valori che finiscono in HTML e CSS. Non modificano l'input: accettano o rifiutano. */
final class Sanitizer
{
    /** Schemi ammessi negli URL. */
    private const URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** Caratteri che potrebbero uscire dalla dichiarazione CSS, più i caratteri di controllo. */
    // Anche /* e */: un commento aperto in un valore spegnerebbe il resto del file CSS.
    // La virgola è ammessa: separa argomenti e liste (minmax(0,1fr), rgba(), ombre multiple) e non chiude la dichiarazione.
    private const CSS_UNSAFE = '/[;{}<>\\\\\x00-\x1F\x7F]|\/\*|\*\//';

    /**
     * Costrutti CSS pericolosi, confrontati senza spazi e in minuscolo.
     * image-set(), image(), cross-fade(), element() e src() caricano risorse anche senza url().
     */
    private const CSS_FORBIDDEN = ['url(', 'expression(', '@import', 'image-set(', 'image(', 'cross-fade(', 'element(', 'src(', 'javascript:'];

    /**
     * URL ammesso: http(s), mailto:, tel: oppure relativo che inizia con "/", "#" o "?".
     * Restituisce l'URL senza spazi ai bordi, oppure null se non è ammesso.
     */
    public static function url(string $u): ?string
    {
        $url = trim($u);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1 || str_contains($url, '\\')) {
            return null;
        }

        // "//host" è un URL di rete relativo al protocollo: non è un relativo locale.
        if (preg_match('/^\/(?!\/)/', $url) === 1 || preg_match('/^[#?]/', $url) === 1) {
            return $url;
        }

        // Lo schema è il testo prima dei due punti; gli spazi interni vanno tolti prima del confronto.
        if (preg_match('/^([^\/?#:]*):/', $url, $m) !== 1) {
            return null;
        }

        $scheme = strtolower((string) preg_replace('/\s+/', '', $m[1]));

        return in_array($scheme, self::URL_SCHEMES, true) ? $url : null;
    }

    /** true se il valore CSS è sicuro da mettere in una dichiarazione. Una stringa vuota non lo è. */
    public static function cssValue(string $v): bool
    {
        if (trim($v) === '' || preg_match(self::CSS_UNSAFE, $v) === 1) {
            return false;
        }

        $compact = strtolower((string) preg_replace('/\s+/', '', $v));
        foreach (self::CSS_FORBIDDEN as $needle) {
            if (str_contains($compact, $needle)) {
                return false;
            }
        }

        return self::balanced($v);
    }

    /**
     * Parentesi e virgolette chiuse: una parentesi o una stringa aperta assorbirebbe le regole successive del file CSS.
     * Dentro una stringa le parentesi non contano; il backslash è già rifiutato, quindi non ci sono escape.
     */
    private static function balanced(string $v): bool
    {
        $depth = 0;
        $quote = null;
        foreach (str_split($v) as $char) {
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth < 0) {
                return false;
            }
        }

        return $depth === 0 && $quote === null;
    }
}
