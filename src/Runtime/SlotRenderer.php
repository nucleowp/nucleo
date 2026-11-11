<?php

declare(strict_types=1);

namespace Nucleo\Runtime;

defined('ABSPATH') || exit;

/**
 * Riempie i marcatori "<!--nc:s N-->...<!--/nc:s-->" con il blocco dello slot N.
 * Scansione con strpos: nessuna regex.
 */
final class SlotRenderer
{
    private const OPEN = '<!--nc:s';
    private const OPEN_END = '-->';
    private const CLOSE = '<!--/nc:s-->';

    /**
     * Slot il cui HTML contiene il sottoalbero compilato del nodo, con gli slot dei figli: si riempie di nuovo.
     * Gli altri slot restituiscono HTML finale (anche testo dell'utente) e non si rileggono.
     */
    private const WRAPPERS = ['nc/visibility', 'nc/bind'];

    /** Profondità massima dei wrapper annidati (visibilità dentro visibilità). */
    private const MAX_DEPTH = 8;

    /**
     * @param array<int,array{block:string,data:array<string,mixed>}> $slots
     * @param callable(string,array<string,mixed>):string $render
     */
    public function fill(string $html, array $slots, callable $render, int $depth = 0): string
    {
        if (strpos($html, self::OPEN) === false) {
            return $html;
        }

        $out = '';
        $pos = 0;
        while (($open = strpos($html, self::OPEN, $pos)) !== false) {
            $out .= substr($html, $pos, $open - $pos);

            $afterOpen = $open + strlen(self::OPEN);
            $tagEnd = strpos($html, self::OPEN_END, $afterOpen);
            if ($tagEnd === false) {
                // Marcatore di apertura non terminato: non e' un marcatore, resta com'e'.
                return $out . substr($html, $open);
            }
            $raw = trim(substr($html, $afterOpen, $tagEnd - $afterOpen));

            $start = $tagEnd + strlen(self::OPEN_END);
            $close = strpos($html, self::CLOSE, $start);
            if ($close === false) {
                // Chiusura mancante: si toglie solo il marcatore di apertura.
                $pos = $start;
                continue;
            }

            $fallback = substr($html, $start, $close - $start);
            $index = ctype_digit($raw) ? (int) $raw : -1;
            if (isset($slots[$index])) {
                $block = $slots[$index]['block'];
                $part = $render($block, $slots[$index]['data']);
                if ($depth < self::MAX_DEPTH && in_array($block, self::WRAPPERS, true)) {
                    $part = $this->fill($part, $slots, $render, $depth + 1);
                }
                $out .= $part;
            } else {
                $out .= $fallback;
            }
            $pos = $close + strlen(self::CLOSE);
        }

        return $out . substr($html, $pos);
    }
}
