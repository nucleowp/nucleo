<?php

declare(strict_types=1);

namespace Nucleo\Assets;

defined('ABSPATH') || exit;

/**
 * Estrae dai fogli CSS del sito solo le regole i cui selettori compaiono nel CSS inviato dal browser.
 * Il CSS inviato serve solo come elenco di selettori: il CSS salvato è quello originale dei fogli.
 */
final class CriticalSubset
{
    /** At-rule che contengono altre regole di stile; le altre (@font-face, @keyframes, @import...) sono ignorate. */
    private const GROUP_AT_RULES = ['media', 'supports', 'layer', 'container'];

    /**
     * @param array<string, string> $sheets url del foglio => cssText
     * @return array{css: string, kept: int, dropped: int}
     */
    public static function extract(string $submitted, array $sheets): array
    {
        $index = [];
        foreach ($sheets as $url => $css) {
            $rules = [];
            self::collect($css, 0, strlen($css), [], $rules);
            foreach ($rules as $rule) {
                $index[self::key($rule['chain'], $rule['selector'])][] = [
                    'url' => (string) $url,
                    'chain' => $rule['chain'],
                    'text' => $rule['text'],
                ];
            }
        }

        $submittedRules = [];
        self::collect($submitted, 0, strlen($submitted), [], $submittedRules);

        $pieces = [];
        $emitted = [];
        $kept = 0;
        $dropped = 0;
        foreach ($submittedRules as $rule) {
            $key = self::key($rule['chain'], $rule['selector']);
            if (!isset($index[$key])) {
                $dropped++;
                continue;
            }
            $kept++;
            // Un duplicato nel CSS inviato conta come regola, ma il suo testo esce una volta sola.
            if (isset($emitted[$key])) {
                continue;
            }
            $emitted[$key] = true;
            foreach ($index[$key] as $match) {
                $pieces[] = self::wrap($match['chain'], self::absolutize($match['text'], $match['url']));
            }
        }

        $css = (string) preg_replace('#</style#i', '', implode("\n", $pieces));

        return ['css' => $css, 'kept' => $kept, 'dropped' => $dropped];
    }

    /**
     * Raccoglie le regole di stile in $css[$from, $to) con la catena delle at-rule che le contengono.
     *
     * @param list<string> $chain
     * @param list<array{chain: list<string>, selector: string, text: string}> $rules
     */
    private static function collect(string $css, int $from, int $to, array $chain, array &$rules): void
    {
        $len = min($to, strlen($css));
        $start = $from;
        $paren = 0;
        $i = $from;
        while ($i < $len) {
            $c = $css[$i];
            if ($c === '/' && ($css[$i + 1] ?? '') === '*') {
                $i = self::skipComment($css, $i);
                continue;
            }
            if ($c === '"' || $c === "'") {
                $i = self::skipString($css, $i);
                continue;
            }
            if ($c === '\\') {
                $i += 2;
                continue;
            }
            if ($c === '(') {
                $paren++;
            } elseif ($c === ')') {
                $paren = max(0, $paren - 1);
            } elseif ($paren === 0 && ($c === ';' || $c === '}')) {
                $start = $i + 1;
            } elseif ($paren === 0 && $c === '{') {
                $close = self::blockEnd($css, $i);
                if ($close < 0) {
                    return;
                }
                $prelude = self::bare(substr($css, $start, $i - $start));
                if ($prelude !== '' && $prelude[0] === '@') {
                    if (preg_match('/^@([a-z-]+)/i', $prelude, $m) === 1 && in_array(strtolower($m[1]), self::GROUP_AT_RULES, true)) {
                        self::collect($css, $i + 1, $close, [...$chain, $prelude], $rules);
                    }
                } elseif ($prelude !== '') {
                    $rules[] = [
                        'chain' => $chain,
                        'selector' => $prelude,
                        'text' => trim(substr($css, $start, $close + 1 - $start)),
                    ];
                }
                $start = $close + 1;
                $i = $close + 1;
                $paren = 0;
                continue;
            }
            $i++;
        }
    }

    /** Indice della '}' che chiude il blocco aperto alla posizione $open, oppure -1 se manca. */
    private static function blockEnd(string $css, int $open): int
    {
        $len = strlen($css);
        $depth = 0;
        for ($i = $open; $i < $len; $i++) {
            $c = $css[$i];
            if ($c === '/' && ($css[$i + 1] ?? '') === '*') {
                $i = self::skipComment($css, $i) - 1;
            } elseif ($c === '"' || $c === "'") {
                $i = self::skipString($css, $i) - 1;
            } elseif ($c === '\\') {
                $i++;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }

    /** Posizione dopo il commento che inizia in $i. */
    private static function skipComment(string $css, int $i): int
    {
        $end = strpos($css, '*/', $i + 2);

        return $end === false ? strlen($css) : $end + 2;
    }

    /** Posizione dopo la stringa che inizia in $i. */
    private static function skipString(string $css, int $i): int
    {
        $quote = $css[$i];
        $len = strlen($css);
        for ($j = $i + 1; $j < $len; $j++) {
            if ($css[$j] === '\\') {
                $j++;
            } elseif ($css[$j] === $quote) {
                return $j + 1;
            }
        }

        return $len;
    }

    /**
     * Rende assoluti gli url() relativi rispetto all'URL del foglio di origine.
     * Restano invariati: data:, http(s):, //host, #frammento. "/percorso" prende schema e host di $base.
     */
    private static function absolutize(string $css, string $base): string
    {
        return (string) preg_replace_callback(
            '#url\(\s*([\'"]?)(.*?)\1\s*\)#i',
            static fn (array $m): string => 'url(' . $m[1] . self::resolve($m[2], $base) . $m[1] . ')',
            $css
        );
    }

    private static function resolve(string $ref, string $base): string
    {
        $ref = trim($ref);
        if ($ref === '' || $ref[0] === '#' || str_starts_with($ref, '//') || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $ref) === 1) {
            return $ref;
        }

        $parts = wp_parse_url($base);
        if (!is_array($parts) || !isset($parts['host'])) {
            return $ref;
        }

        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $basePath = $parts['path'] ?? '/';
        $slash = strrpos($basePath, '/');
        $dir = $slash === false ? '/' : substr($basePath, 0, $slash + 1);

        // La query e il frammento del riferimento restano attaccati al percorso risolto.
        $cut = strcspn($ref, '?#');
        $refPath = substr($ref, 0, $cut);
        $suffix = substr($ref, $cut);

        $path = str_starts_with($refPath, '/') ? $refPath : $dir . $refPath;

        return $origin . self::normalizePath($path) . $suffix;
    }

    /** Risolve i segmenti "." e ".." senza salire sopra la radice. */
    private static function normalizePath(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
            } elseif ($segment !== '.') {
                $out[] = $segment;
            }
        }

        return implode('/', $out);
    }

    /** Testo del preludio senza commenti e senza spazi ai bordi. */
    private static function bare(string $prelude): string
    {
        return trim((string) preg_replace('#/\*.*?\*/#s', '', $prelude));
    }

    /**
     * @param list<string> $chain
     */
    private static function key(array $chain, string $selector): string
    {
        $parts = [];
        foreach ($chain as $prelude) {
            $parts[] = self::normalize($prelude);
        }
        $parts[] = self::normalizeSelector($selector);

        return implode("\n", $parts);
    }

    private static function normalize(string $text): string
    {
        $text = strtolower(trim((string) preg_replace('/\s+/', ' ', self::bare($text))));
        $text = str_replace("'", '"', $text);
        $text = (string) preg_replace('/ ?([>+~,()]) ?/', '$1', $text);

        return (string) preg_replace('/ ?: ?/', ':', $text);
    }

    private static function normalizeSelector(string $selector): string
    {
        return (string) preg_replace(
            '#(?<!:):(before|after|first-line|first-letter)(?![\w-])#',
            '::$1',
            self::normalize($selector)
        );
    }

    /**
     * @param list<string> $chain
     */
    private static function wrap(array $chain, string $text): string
    {
        foreach (array_reverse($chain) as $prelude) {
            $text = $prelude . '{' . $text . '}';
        }

        return $text;
    }
}
