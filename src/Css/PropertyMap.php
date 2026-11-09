<?php

declare(strict_types=1);

namespace Nucleo\Css;

/**
 * Maps document style keys (camelCase) to CSS properties and supports.style groups.
 * Numbers get "px" unless the key is unitless.
 */
final class PropertyMap
{
    /** key → [supports.style group, CSS property, selettore facoltativo dentro il nodo] */
    private const MAP = [
        'padding' => ['spacing', 'padding'],
        'margin' => ['spacing', 'margin'],
        'gap' => ['spacing', 'gap'],
        'width' => ['size', 'width'],
        'maxWidth' => ['size', 'max-width'],
        'minWidth' => ['size', 'min-width'],
        'maxHeight' => ['size', 'max-height'],
        'minHeight' => ['size', 'min-height'],
        'height' => ['size', 'height'],
        'aspectRatio' => ['size', 'aspect-ratio'],
        'color' => ['color', 'color'],
        'background' => ['color', 'background'],
        'accentColor' => ['color', 'accent-color'],
        'fontSize' => ['typography', 'font-size'],
        'fontWeight' => ['typography', 'font-weight'],
        'lineHeight' => ['typography', 'line-height'],
        'letterSpacing' => ['typography', 'letter-spacing'],
        'fontFamily' => ['typography', 'font-family'],
        'fontStyle' => ['typography', 'font-style'],
        'textAlign' => ['typography', 'text-align'],
        'textDecoration' => ['typography', 'text-decoration'],
        'textTransform' => ['typography', 'text-transform'],
        'whiteSpace' => ['typography', 'white-space'],
        'display' => ['layout', 'display'],
        'columns' => ['layout', 'grid-template-columns'],
        'direction' => ['layout', 'flex-direction'],
        'wrap' => ['layout', 'flex-wrap'],
        'justify' => ['layout', 'justify-content'],
        'align' => ['layout', 'align-items'],
        'order' => ['layout', 'order'],
        'flexGrow' => ['layout', 'flex-grow'],
        'flexShrink' => ['layout', 'flex-shrink'],
        'flexBasis' => ['layout', 'flex-basis'],
        'alignSelf' => ['layout', 'align-self'],
        'justifySelf' => ['layout', 'justify-self'],
        'border' => ['border', 'border'],
        'borderTop' => ['border', 'border-top'],
        'borderRight' => ['border', 'border-right'],
        'borderBottom' => ['border', 'border-bottom'],
        'borderLeft' => ['border', 'border-left'],
        'borderRadius' => ['border', 'border-radius'],
        'boxShadow' => ['border', 'box-shadow'],
        'opacity' => ['effects', 'opacity'],
        'objectFit' => ['effects', 'object-fit'],
        'filter' => ['effects', 'filter'],
        'transform' => ['effects', 'transform'],
        'position' => ['position', 'position'],
        'top' => ['position', 'top'],
        'right' => ['position', 'right'],
        'bottom' => ['position', 'bottom'],
        'left' => ['position', 'left'],
        'inset' => ['position', 'inset'],
        'zIndex' => ['position', 'z-index'],
        'scrollMarginTop' => ['position', 'scroll-margin-top'],
        'overflow' => ['position', 'overflow'],
        'overflowX' => ['position', 'overflow-x'],
        'overflowY' => ['position', 'overflow-y'],
        // Gruppo menu (nc/menu): il terzo elemento è il selettore dentro il nodo.
        'itemColor' => ['menu', 'color', ' .nc-menu__link'],
        'itemColorHover' => ['menu', 'color', ' .nc-menu__link:is(:hover,:focus-visible)'],
        'itemColorActive' => ['menu', 'color', ' .nc-menu__link:is(.is-current,.is-ancestor)'],
        'itemBackground' => ['menu', 'background', ' .nc-menu__link'],
        'itemBackgroundHover' => ['menu', 'background', ' .nc-menu__link:is(:hover,:focus-visible)'],
        'itemBackgroundActive' => ['menu', 'background', ' .nc-menu__link:is(.is-current,.is-ancestor)'],
        'itemDecoration' => ['menu', 'text-decoration', ' .nc-menu__link'],
        'itemDecorationHover' => ['menu', 'text-decoration', ' .nc-menu__link:is(:hover,:focus-visible)'],
        'itemWeight' => ['menu', 'font-weight', ' .nc-menu__link'],
        'itemWeightActive' => ['menu', 'font-weight', ' .nc-menu__link:is(.is-current,.is-ancestor)'],
        'itemPadding' => ['menu', 'padding', ' .nc-menu__link'],
        'itemRadius' => ['menu', 'border-radius', ' .nc-menu__link'],
        'itemGap' => ['menu', 'gap', ' .nc-menu__list'],
        'submenuBackground' => ['menu', '--nc-menu-submenu-bg'],
        'panelWidth' => ['menu', '--nc-menu-panel-width'],
        'panelBackground' => ['menu', '--nc-menu-panel-bg'],
        'animation' => ['motion', 'animation'],
        'animationDuration' => ['motion', 'animation-duration'],
        'animationDelay' => ['motion', 'animation-delay'],
        'transition' => ['motion', 'transition'],
        // Parti interne dei blocchi interattivi (gruppi controls e link): vedi PartMap.
        ...PartMap::MAP,
    ];

    /**
     * Stati del nodo: la chiave base più il suffisso ("backgroundHover", "colorCurrent") stila il nodo in quello stato.
     * Hover vale anche per il focus da tastiera dentro il nodo; Current è la classe is-current messa da nc/toc e dai menu.
     */
    /** Gruppi delle chiavi ammesse dentro style.parts.<parte>; le parti non hanno visibilità né position. */
    public const PART_GROUPS = ['size', 'spacing', 'typography', 'color', 'border', 'effects', 'layout', 'motion'];

    public const STATES = ['Hover' => ':is(:hover,:focus-within)', 'Current' => '.is-current'];

    /** Chiavi base che accettano un suffisso di stato: stesso gruppo, stessi valori ammessi. */
    public const STATE_BASES = [
        'color', 'background', 'border', 'borderTop', 'borderRight', 'borderBottom', 'borderLeft',
        'boxShadow', 'opacity', 'filter', 'fontWeight', 'textDecoration',
    ];

    /** Proprietà CSS che prendono un numero senza unità; vale anche per ogni variabile che finisce in -weight. */
    private const UNITLESS = ['font-weight', 'line-height', 'opacity', 'order', 'flex-grow', 'flex-shrink', 'z-index', 'column-count'];

    /** Valori di align-self e justify-self. */
    private const SELF_ALIGN = ['auto', 'normal', 'stretch', 'center', 'start', 'end', 'flex-start', 'flex-end', 'self-start', 'self-end', 'baseline'];

    private const OVERFLOW = ['visible', 'hidden', 'clip', 'scroll', 'auto'];

    private const DECORATION = ['none', 'underline', 'line-through', 'overline'];

    private const TRANSFORM = ['none', 'uppercase', 'lowercase', 'capitalize'];

    private const WHITE_SPACE = ['normal', 'nowrap', 'pre-line'];

    /** Chiavi con un elenco chiuso di valori. */
    private const ENUMS = [
        'position' => ['static', 'relative', 'absolute', 'sticky', 'fixed'],
        'overflow' => self::OVERFLOW,
        'overflowX' => self::OVERFLOW,
        'overflowY' => self::OVERFLOW,
        'textDecoration' => self::DECORATION,
        'textTransform' => self::TRANSFORM,
        'whiteSpace' => self::WHITE_SPACE,
        'fontStyle' => ['normal', 'italic', 'oblique'],
        'itemDecoration' => self::DECORATION,
        'itemDecorationHover' => self::DECORATION,
        ...PartMap::ENUMS,
        'alignSelf' => self::SELF_ALIGN,
        'justifySelf' => self::SELF_ALIGN,
    ];

    /** Chiavi numeriche senza unità: numero >= 0 (flex-grow, flex-shrink) o intero (order). */
    private const NUMBERS = ['flexGrow' => 'ratio', 'flexShrink' => 'ratio', 'order' => 'integer'];

    /** Characters that could break out of a declaration: ; { } < > backslash and control characters. */
    // Anche /* e */: un commento aperto in un valore spegnerebbe il resto del file CSS.
    private const UNSAFE = '/[;{}<>\\\\\x00-\x1F\x7F]|\/\*|\*\//';

    /**
     * Style keys grouped as in supports.style, for editors.
     *
     * @return array<string,list<string>>
     */
    public static function keysByGroup(): array
    {
        $out = [];
        foreach ([...array_keys(self::MAP), ...self::stateKeys()] as $key) {
            $group = self::group($key);
            if ($group !== null) {
                $out[$group][] = $key;
            }
        }

        return $out;
    }

    /** @return list<string> chiavi di stato, nell'ordine di STATE_BASES e STATES */
    private static function stateKeys(): array
    {
        $out = [];
        foreach (self::STATE_BASES as $base) {
            foreach (array_keys(self::STATES) as $suffix) {
                $out[] = $base . $suffix;
            }
        }

        return $out;
    }

    /** Chiave base di una chiave di stato ("colorHover" → "color"); la chiave stessa per le altre. */
    public static function baseKey(string $key): string
    {
        return self::stateOf($key)[0];
    }

    /** @return array{0:string,1:string} [chiave base, selettore dello stato]; selettore vuoto se la chiave non è di stato */
    private static function stateOf(string $key): array
    {
        if (!isset(self::MAP[$key])) {
            foreach (self::STATES as $suffix => $selector) {
                $base = substr($key, 0, -strlen($suffix));
                if (str_ends_with($key, $suffix) && in_array($base, self::STATE_BASES, true)) {
                    return [$base, $selector];
                }
            }
        }

        return [$key, ''];
    }

    /** @return ?string supports.style group of the key, null if unknown */
    public static function group(string $key): ?string
    {
        $base = self::baseKey($key);

        return isset(self::MAP[$base]) ? self::MAP[$base][0] : null;
    }

    /** Selettore della chiave dentro il nodo (" .nc-menu__link") o dello stato (":is(:hover,:focus-within)"); vuoto per le altre. */
    public static function selector(string $key): string
    {
        [$base, $state] = self::stateOf($key);

        return $state !== '' ? $state : (self::MAP[$base][2] ?? '');
    }

    /** @return list<string> valori ammessi per la chiave; lista vuota se il valore è libero */
    public static function allowed(string $key): array
    {
        $key = self::baseKey($key);
        if ($key === 'resultsColumns') {
            return ['integer 1..12'];
        }
        if ($key === 'zIndex') {
            return ['integer -9999..9999'];
        }
        if ($key === 'filter') {
            return ['grayscale(N), N 0..1 or N%'];
        }
        if (isset(self::NUMBERS[$key])) {
            return [self::NUMBERS[$key] === 'ratio' ? 'number >= 0' : 'integer -9999..9999'];
        }

        return self::ENUMS[$key] ?? [];
    }

    /** true se il valore è ammesso per la chiave: elenco chiuso o, per zIndex, intero tra -9999 e 9999. */
    public static function accepts(string $key, mixed $value): bool
    {
        $key = self::baseKey($key);
        if ($key === 'resultsColumns') {
            // Solo un numero di colonne: il valore diventa repeat(N,minmax(0,1fr)), nessun testo libero.
            $int = is_int($value) ? $value : (is_string($value) && preg_match('/^\d{1,2}$/D', $value) === 1 ? (int) $value : null);

            return $int !== null && $int >= 1 && $int <= 12;
        }
        if ($key === 'zIndex') {
            $int = is_int($value) ? $value : (is_string($value) && preg_match('/^-?\d{1,4}$/D', $value) === 1 ? (int) $value : null);

            return $int !== null && $int >= -9999 && $int <= 9999;
        }
        if ($key === 'filter') {
            return self::filter($value);
        }
        if (isset(self::ENUMS[$key])) {
            return is_string($value) && in_array($value, self::ENUMS[$key], true);
        }
        if (isset(self::NUMBERS[$key])) {
            // Le parole chiave globali restano ammesse: documenti salvati prima del controllo numerico (order: "initial").
            if (is_string($value) && in_array($value, ['initial', 'inherit', 'unset', 'revert'], true)) {
                return true;
            }

            return self::NUMBERS[$key] === 'ratio' ? self::ratio($value) : self::integer($value);
        }

        return true;
    }

    /** Numero >= 0, anche come testo ("1", "0.5"). */
    private static function ratio(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) && $value >= 0;
        }

        return is_string($value) && preg_match('/^\d{1,4}(\.\d{1,4})?$/D', $value) === 1;
    }

    /** Solo grayscale(N): N tra 0 e 1, oppure N% tra 0% e 100%. */
    private static function filter(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^grayscale\((\d{1,3}(?:\.\d{1,4})?)(%?)\)$/D', $value, $m) !== 1) {
            return false;
        }

        return (float) $m[1] <= ($m[2] === '%' ? 100.0 : 1.0);
    }

    /** Intero tra -9999 e 9999, anche come testo. */
    private static function integer(mixed $value): bool
    {
        $int = is_int($value) ? $value : (is_string($value) && preg_match('/^-?\d{1,4}$/D', $value) === 1 ? (int) $value : null);

        return $int !== null && $int >= -9999 && $int <= 9999;
    }

    /**
     * @return list<string> e.g. ["padding:1rem"]; empty if the key is unknown or the value is unusable
     */
    public static function declarations(string $key, string|int|float $value): array
    {
        $key = self::baseKey($key);
        if (!isset(self::MAP[$key])) {
            return [];
        }

        // Il gruppo motion ha più dichiarazioni per valore (preset) e una sua validazione: vedi Motion.
        if (self::MAP[$key][0] === 'motion') {
            return Motion::declarations($key, $value);
        }

        if (!self::accepts($key, $value)) {
            return [];
        }

        $text = self::valueText($key, $value);
        if ($text === null || $text === '' || preg_match(self::UNSAFE, $text) === 1) {
            return [];
        }

        return [self::MAP[$key][1] . ':' . $text];
    }

    private static function valueText(string $key, string|int|float $value): ?string
    {
        if ($key === 'resultsColumns') {
            return 'repeat(' . (int) $value . ',minmax(0,1fr))';
        }

        if ($key === 'columns') {
            $isCount = is_string($value)
                ? preg_match('/^\d+$/', $value) === 1
                : floor((float) $value) === (float) $value;

            if (!$isCount) {
                return is_string($value) ? $value : null;
            }

            $count = (int) $value;

            return $count >= 1 ? 'repeat(' . $count . ',minmax(0,1fr))' : null;
        }

        if (is_string($value)) {
            return $value;
        }

        // L'unità segue la proprietà CSS, non il nome della chiave: un peso nuovo non diventa mai 400px.
        $property = self::MAP[$key][1];
        if (in_array($property, self::UNITLESS, true) || str_ends_with($property, '-weight')) {
            return (string) $value;
        }

        return $value . 'px';
    }
}
