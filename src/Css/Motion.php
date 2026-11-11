<?php

declare(strict_types=1);

namespace Nucleo\Css;

defined('ABSPATH') || exit;

/**
 * Gruppo di stile "motion": preset di animazione con i loro @keyframes, durate, ritardi e una transizione.
 * Un valore non valido non produce dichiarazioni; accepts() e allowed() servono al validatore per segnalarlo.
 */
final class Motion
{
    public const KEYFRAME_PREFIX = 'nc-k-';

    /** Ordine di scrittura delle chiavi motion: l'animation shorthand prima delle sue sotto-proprietà. */
    public const ORDER = ['animation', 'animationDuration', 'animationDelay', 'transition'];

    /** nome → [shorthand senza nome, corpo di @keyframes, dichiarazioni extra] */
    private const PRESETS = [
        'fade-in' => ['.6s ease both', 'from{opacity:0}', []],
        'slide-up' => ['.6s ease both', 'from{opacity:0;transform:translateY(16px)}', []],
        'pop' => ['.5s cubic-bezier(.2,1.4,.4,1) both', 'from{transform:scale(.6);opacity:0}', []],
        'spin' => ['.8s linear infinite', 'to{transform:rotate(360deg)}', []],
        'pulse' => ['1.2s ease-in-out infinite', '50%{opacity:.4}', []],
        'shimmer' => [
            '1.3s linear infinite',
            'from{background-position:-400px 0}to{background-position:400px 0}',
            [
                'background-image:linear-gradient(90deg,#fff0 0,#fff9 50%,#fff0 100%)',
                'background-size:400px 100%',
                'background-repeat:no-repeat',
            ],
        ],
        'progress' => [
            '1.1s ease-in-out infinite',
            '0%{transform:scaleX(0);transform-origin:left}50%{transform:scaleX(1);transform-origin:left}'
            . '50.01%{transform:scaleX(1);transform-origin:right}100%{transform:scaleX(0);transform-origin:right}',
            [],
        ],
    ];

    private const EASINGS = ['ease', 'linear', 'ease-in', 'ease-out', 'ease-in-out'];

    /** Proprietà di una transizione: all o un nome CSS (filter, background-color, translate...). */
    private const TRANSITION_PROPERTY = '/^(?:all|[a-z][a-z-]{1,40})$/D';

    /** Durata o ritardo in secondi o millisecondi: "0.8s", "800ms". */
    private const TIME = '/^(\d+|\d*\.\d+)(s|ms)$/D';

    /**
     * @return list<string> dichiarazioni CSS della chiave; vuoto se il valore non è valido
     */
    public static function declarations(string $key, string|int|float $value): array
    {
        return match ($key) {
            'animation' => self::animation($value),
            'animationDuration' => self::time('animation-duration', $value),
            'animationDelay' => self::time('animation-delay', $value),
            'transition' => self::transition($value),
            default => [],
        };
    }

    /** @return list<string> valori ammessi, per il messaggio di errore */
    public static function allowed(string $key): array
    {
        return match ($key) {
            'animation' => [...array_keys(self::PRESETS), 'none'],
            'animationDuration', 'animationDelay' => ['0.8s', '800ms', 'number in ms'],
            'transition' => [
                '<property> <duration> [easing] [delay], more transitions separated by commas',
                'all or any CSS property: opacity, transform, filter, background-color...',
                ...self::EASINGS,
            ],
            default => [],
        };
    }

    /** Vero se il valore produce dichiarazioni per la chiave. I token ($...) si risolvono prima di arrivare qui. */
    public static function accepts(string $key, mixed $value): bool
    {
        return (is_string($value) || is_int($value) || is_float($value))
            && self::declarations($key, $value) !== [];
    }

    /**
     * @param list<string> $nomi nomi di preset; i duplicati e i nomi sconosciuti si ignorano
     * @return string @keyframes dei preset indicati, una volta ciascuno
     */
    public static function keyframes(array $nomi): string
    {
        $css = '';
        foreach (array_unique($nomi) as $nome) {
            if (isset(self::PRESETS[$nome])) {
                $css .= '@keyframes ' . self::KEYFRAME_PREFIX . $nome . '{' . self::PRESETS[$nome][1] . '}';
            }
        }

        return $css;
    }

    /** Vero per le dichiarazioni del gruppo motion: animation, animation-* e transition. */
    public static function isDeclaration(string $declaration): bool
    {
        return preg_match('/^(animation|transition)[:-]/', $declaration) === 1;
    }

    /** Nome del preset di una dichiarazione "animation:nc-k-<nome> ...", altrimenti null. */
    public static function presetOf(string $declaration): ?string
    {
        return preg_match('/^animation:' . self::KEYFRAME_PREFIX . '([a-z-]+) /', $declaration, $m) === 1
            ? $m[1]
            : null;
    }

    /** @return list<string> */
    private static function animation(string|int|float $value): array
    {
        if ($value === 'none') {
            return ['animation:none'];
        }
        if (!is_string($value) || !isset(self::PRESETS[$value])) {
            return [];
        }

        $preset = self::PRESETS[$value];

        return array_merge(['animation:' . self::KEYFRAME_PREFIX . $value . ' ' . $preset[0]], $preset[2]);
    }

    /** @return list<string> */
    private static function time(string $property, string|int|float $value): array
    {
        if (is_string($value)) {
            return preg_match(self::TIME, $value) === 1 ? [$property . ':' . $value] : [];
        }
        if (!is_finite((float) $value) || $value < 0) {
            return [];
        }

        return [$property . ':' . rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.') . 'ms'];
    }

    /**
     * Una o più transizioni separate da virgole: "<proprietà> <durata> [easing] [ritardo]" ciascuna.
     * @return list<string>
     */
    private static function transition(string|int|float $value): array
    {
        if (!is_string($value)) {
            return [];
        }

        $items = [];
        foreach (explode(',', $value) as $item) {
            $parts = preg_split('/\s+/', trim($item)) ?: [];
            if (
                count($parts) < 2
                || preg_match(self::TRANSITION_PROPERTY, $parts[0]) !== 1
                || preg_match(self::TIME, $parts[1]) !== 1
            ) {
                return [];
            }

            // Dopo la durata: easing opzionale, poi ritardo opzionale.
            $tail = array_slice($parts, 2);
            if ($tail !== [] && in_array($tail[0], self::EASINGS, true)) {
                array_shift($tail);
            }
            if (count($tail) > 1 || ($tail !== [] && preg_match(self::TIME, $tail[0]) !== 1)) {
                return [];
            }
            $items[] = implode(' ', $parts);
        }

        return ['transition:' . implode(',', $items)];
    }
}
