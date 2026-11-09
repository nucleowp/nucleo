<?php

declare(strict_types=1);

namespace Nucleo\Tokens;

use InvalidArgumentException;
use Nucleo\Schema\Sanitizer;

/**
 * Token di design: valori grezzi, variabili CSS in :root e export per theme.json.
 * Spazi e testi in rem (16px = 1rem), contenitori in px.
 */
final class TokenSet implements TokenResolver
{
    private const TOKEN_PATTERN = '/^\$[a-z]+\.[a-z0-9-]+$/';
    /** Formati colore ammessi nel gruppo color (esclusi i nomi e le parole chiave). */
    private const COLOR_PATTERNS = [
        '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\z/i',
        '/^(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\((?:[0-9.%, \/-]|deg)+\)\z/i',
        '/^var\(--nc-[a-z0-9-]+\)\z/i',
    ];
    /** Parole chiave colore: transparent, currentColor e i nomi CSS base. */
    private const COLOR_KEYWORDS = [
        'transparent', 'currentcolor',
        'black', 'silver', 'gray', 'white', 'maroon', 'red', 'purple', 'fuchsia',
        'green', 'lime', 'olive', 'yellow', 'navy', 'blue', 'teal', 'aqua',
    ];
    /** Chiave di un token nuovo: stessa forma accettata nei riferimenti $gruppo.chiave. */
    private const KEY_PATTERN = '/^[a-z0-9-]{1,24}$/D';
    /** Chiavi al massimo per gruppo, default compresi. */
    private const MAX_KEYS = 48;
    private const ROOT_PX = 16;
    private const VIEWPORT_MIN = 360;
    private const VIEWPORT_MAX = 1920;

    private const DEFAULTS = [
        'color' => [
            'primary' => '#2563eb',
            'secondary' => '#0f766e',
            'accent' => '#b45309',
            'text' => '#1a1a1a',
            'muted' => '#4a4a4a',
            'background' => '#ffffff',
            'surface' => '#f5f5f5',
            'border' => '#d4d4d4',
        ],
        'space' => [
            '0' => 0.0,
            '1' => 0.25,
            '2' => 0.5,
            '3' => 0.75,
            '4' => 1.0,
            '5' => 1.25,
            '6' => 1.5,
            '8' => 2.0,
            '10' => 2.5,
            '12' => 3.0,
            '16' => 4.0,
        ],
        'text' => [
            'xs' => [0.75, 0.875],
            'sm' => [0.875, 1.0],
            'base' => [1.0, 1.125],
            'lg' => [1.125, 1.375],
            'xl' => [1.25, 1.625],
            '2xl' => [1.5, 2.0],
            '3xl' => [1.875, 2.5],
            '4xl' => [2.25, 3.0],
            '5xl' => [3.0, 4.0],
            '6xl' => [3.75, 5.0],
        ],
        'font' => [
            'body' => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
            'heading' => 'ui-serif, Georgia, "Times New Roman", serif',
        ],
        'radius' => [
            'sm' => '0.25rem',
            'md' => '0.5rem',
            'lg' => '1rem',
            'full' => '9999px',
        ],
        'shadow' => [
            'sm' => '0 1px 2px rgba(0,0,0,0.05)',
            'md' => '0 4px 6px -1px rgba(0,0,0,0.1)',
            'lg' => '0 10px 15px -3px rgba(0,0,0,0.1)',
        ],
        'container' => [
            'sm' => 640,
            'md' => 768,
            'lg' => 1024,
            'xl' => 1280,
        ],
    ];

    /** @param array<string, array<int|string, mixed>> $groups */
    private function __construct(private readonly array $groups)
    {
    }

    public static function defaults(): self
    {
        return new self(self::DEFAULTS);
    }

    /**
     * Unisce i valori dati sui default. Le chiavi nuove si aggiungono al gruppo.
     * Gruppi sconosciuti, chiavi non valide e valori non sicuri per il CSS sono rifiutati.
     *
     * @param array<string, array<int|string, mixed>> $groups
     */
    public static function fromArray(array $groups): self
    {
        $merged = self::DEFAULTS;
        foreach ($groups as $group => $values) {
            if (!is_string($group) || !array_key_exists($group, self::DEFAULTS)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new InvalidArgumentException(sprintf(/* translators: %s: token group name. */ __('Unknown token group: %s', 'nucleo'), $group));
            }
            if (!is_array($values)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new InvalidArgumentException(sprintf(/* translators: %s: token group name. */ __('Group %s must be an array', 'nucleo'), $group));
            }
            foreach ($values as $key => $value) {
                // Chiavi nuove ammesse (space.20, color.brand): i documenti le citano come $gruppo.chiave.
                if (!array_key_exists($key, self::DEFAULTS[$group]) && preg_match(self::KEY_PATTERN, (string) $key) !== 1) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                    throw new InvalidArgumentException(sprintf(/* translators: 1: token group name, 2: token key. */ __('Invalid token key: %1$s.%2$s (use lowercase letters, digits and -, at most 24 characters)', 'nucleo'), $group, $key));
                }
                $merged[$group][$key] = self::normalize($group, $key, $value);
            }
            if (count($merged[$group]) > self::MAX_KEYS) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new InvalidArgumentException(sprintf(/* translators: 1: token group name, 2: maximum number of keys. */ __('Too many tokens in group %1$s (maximum %2$d)', 'nucleo'), $group, self::MAX_KEYS));
            }
        }

        return new self($merged);
    }

    public function resolve(string $value): ?string
    {
        if (!$this->isToken($value)) {
            return $value;
        }
        $hit = $this->lookup($value);
        if ($hit === null) {
            return null;
        }
        [$group, $key] = $hit;

        return "var(--nc-$group-$key)";
    }

    public function isToken(string $value): bool
    {
        return preg_match(self::TOKEN_PATTERN, $value) === 1;
    }

    /** Valore grezzo del token; per i testi il valore massimo. Null se il token non esiste. */
    public function raw(string $token): ?string
    {
        $hit = $this->lookup($token);
        if ($hit === null) {
            return null;
        }
        [$group, $key] = $hit;
        $v = $this->groups[$group][$key];

        return match ($group) {
            'space' => self::rem($v),
            'text' => self::rem($v[1]),
            'container' => $v . 'px',
            default => (string) $v,
        };
    }

    /** @return array<string, array<int|string, mixed>> */
    public function toArray(): array
    {
        return $this->groups;
    }

    public function toCss(): string
    {
        $decls = [];
        foreach ($this->groups as $group => $values) {
            foreach ($values as $key => $value) {
                $decls[] = "--nc-$group-$key:" . self::cssValue($group, $value);
            }
        }

        return ':root{' . implode(';', $decls) . '}';
    }

    /** @return array<string, mixed> */
    public function toThemeJson(): array
    {
        $palette = [];
        foreach ($this->groups['color'] as $key => $value) {
            $palette[] = ['slug' => (string) $key, 'name' => ucfirst((string) $key), 'color' => $value];
        }

        $fontSizes = [];
        foreach ($this->groups['text'] as $key => [$min, $max]) {
            $fontSizes[] = [
                'slug' => (string) $key,
                'name' => ucfirst((string) $key),
                'size' => self::rem($max),
                'fluid' => ['min' => self::rem($min), 'max' => self::rem($max)],
            ];
        }

        $fontFamilies = [];
        foreach ($this->groups['font'] as $key => $value) {
            $fontFamilies[] = ['slug' => (string) $key, 'name' => ucfirst((string) $key), 'fontFamily' => $value];
        }

        $spacing = [];
        foreach ($this->groups['space'] as $key => $value) {
            $spacing[] = ['slug' => (string) $key, 'name' => (string) $key, 'size' => self::rem($value)];
        }

        return [
            'version' => 3,
            'settings' => [
                'color' => ['palette' => $palette],
                'typography' => ['fontSizes' => $fontSizes, 'fontFamilies' => $fontFamilies],
                'spacing' => ['spacingSizes' => $spacing],
                'layout' => [
                    'contentSize' => $this->groups['container']['lg'] . 'px',
                    'wideSize' => $this->groups['container']['xl'] . 'px',
                ],
            ],
        ];
    }

    /** @return array{string, string}|null gruppo e chiave se il token esiste */
    private function lookup(string $token): ?array
    {
        if (!$this->isToken($token)) {
            return null;
        }
        [$group, $key] = explode('.', substr($token, 1), 2);

        return array_key_exists($key, $this->groups[$group] ?? []) ? [$group, $key] : null;
    }

    private static function normalize(string $group, int|string $key, mixed $value): mixed
    {
        $where = "$group.$key";
        $bad = static fn (string $what): InvalidArgumentException => new InvalidArgumentException("$where: $what");

        if ($group === 'space') {
            if (!self::isNumber($value) || $value < 0) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw $bad(__('expected a number of rem >= 0', 'nucleo'));
            }
            return (float) $value;
        }
        if ($group === 'text') {
            $pair = is_array($value) ? array_values($value) : [];
            if (count($pair) !== 2 || !self::isNumber($pair[0]) || !self::isNumber($pair[1]) || $pair[0] < 0 || $pair[0] > $pair[1]) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw $bad(__('expected a [minRem, maxRem] pair with 0 <= min <= max', 'nucleo'));
            }
            return [(float) $pair[0], (float) $pair[1]];
        }
        if ($group === 'container') {
            if (!is_int($value) || $value <= 0) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw $bad(__('expected an integer in px > 0', 'nucleo'));
            }
            return $value;
        }
        if ($group === 'color') {
            if (!is_string($value) || !self::isColor($value)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw $bad(__('expected a valid CSS color', 'nucleo'));
            }
            return $value;
        }
        // font, radius, shadow: stesso controllo dei valori di stile dei documenti (niente url(), image-set(), commenti,
        // parentesi o virgolette aperte): il valore finisce in :root e in ogni dichiarazione che cita il token.
        if (!is_string($value) || !Sanitizer::cssValue($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw $bad(__('invalid CSS value', 'nucleo'));
        }

        return $value;
    }

    private static function isColor(string $value): bool
    {
        if (in_array(strtolower($value), self::COLOR_KEYWORDS, true)) {
            return true;
        }
        foreach (self::COLOR_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function isNumber(mixed $v): bool
    {
        return (is_int($v) || is_float($v)) && is_finite((float) $v);
    }

    private static function cssValue(string $group, mixed $value): string
    {
        return match ($group) {
            'space' => self::rem($value),
            'text' => self::fluid($value[0], $value[1]),
            'container' => $value . 'px',
            default => (string) $value,
        };
    }

    /** clamp() tra 360px e 1920px di viewport, con 1rem = 16px. Min uguale a max: valore fisso. */
    private static function fluid(float $min, float $max): string
    {
        if ($min === $max) {
            return self::rem($min);
        }
        $minPx = $min * self::ROOT_PX;
        $maxPx = $max * self::ROOT_PX;
        $slope = ($maxPx - $minPx) / (self::VIEWPORT_MAX - self::VIEWPORT_MIN);
        $interceptRem = ($minPx - self::VIEWPORT_MIN * $slope) / self::ROOT_PX;
        $vw = 100 * $slope;

        return sprintf(
            'clamp(%s, calc(%s %s %svw), %s)',
            self::rem($min),
            self::rem($interceptRem),
            $vw < 0 ? '-' : '+',
            self::num(abs($vw)),
            self::rem($max),
        );
    }

    private static function rem(float $n): string
    {
        return self::num($n) . 'rem';
    }

    /** Numero con al massimo 4 decimali, senza zeri finali. */
    private static function num(float $n): string
    {
        $s = rtrim(rtrim(sprintf('%.4f', $n), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }
}
