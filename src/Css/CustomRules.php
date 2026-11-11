<?php

declare(strict_types=1);

namespace Nucleo\Css;

defined('ABSPATH') || exit;

use Nucleo\Schema\ValidationError;

/**
 * Regole di style.custom in forma strutturata: {when, target, child, nth, state, pseudo, css}.
 * Il selettore si compone solo da liste chiuse; i valori delle dichiarazioni non si controllano qui.
 */
final class CustomRules
{
    public const MAX_RULES = 20;

    /** Stato → suffisso CSS. "when" va sul nodo, "state" sul target. */
    public const STATES = [
        'hover' => ':hover',
        'focus' => ':focus',
        'focus-visible' => ':focus-visible',
        'focus-within' => ':focus-within',
        'active' => ':active',
        'visited' => ':visited',
        'checked' => ':checked',
        'disabled' => ':disabled',
        'open' => '[open]',
        'empty' => ':empty',
        'current' => '.is-current',
    ];

    public const PSEUDOS = [
        'before',
        'after',
        'marker',
        'placeholder',
        'first-letter',
        'first-line',
        'selection',
        'backdrop',
    ];

    /** Tag HTML ammessi come target. */
    public const ELEMENTS = [
        'a', 'li', 'ul', 'ol', 'p', 'span', 'img', 'svg', 'path', 'legend', 'label',
        'input', 'select', 'textarea', 'button',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'em', 'figure', 'figcaption', 'table', 'tr', 'td', 'th',
        'summary', 'details', 'nav', 'header', 'footer', 'picture', 'video', 'iframe',
        'blockquote', 'hr', 'small', 'code',
    ];

    /** Proprietà CSS standard in kebab-case (longhand e shorthand). */
    public const PROPERTIES = [
        'align-content', 'align-items', 'align-self', 'all', 'animation', 'animation-delay',
        'animation-direction', 'animation-duration', 'animation-fill-mode', 'animation-iteration-count',
        'animation-name', 'animation-play-state', 'animation-timing-function', 'appearance',
        'aspect-ratio', 'backdrop-filter', 'backface-visibility', 'background', 'background-attachment',
        'background-blend-mode', 'background-clip', 'background-color', 'background-image',
        'background-origin', 'background-position', 'background-position-x', 'background-position-y',
        'background-repeat', 'background-size', 'block-size', 'border', 'border-block',
        'border-block-color', 'border-block-end', 'border-block-end-color', 'border-block-end-style',
        'border-block-end-width', 'border-block-start', 'border-block-start-color',
        'border-block-start-style', 'border-block-start-width', 'border-block-style',
        'border-block-width', 'border-bottom', 'border-bottom-color', 'border-bottom-left-radius',
        'border-bottom-right-radius', 'border-bottom-style', 'border-bottom-width', 'border-collapse',
        'border-color', 'border-end-end-radius', 'border-end-start-radius', 'border-image',
        'border-image-outset', 'border-image-repeat', 'border-image-slice', 'border-image-source',
        'border-image-width', 'border-inline', 'border-inline-color', 'border-inline-end',
        'border-inline-end-color', 'border-inline-end-style', 'border-inline-end-width',
        'border-inline-start', 'border-inline-start-color', 'border-inline-start-style',
        'border-inline-start-width', 'border-inline-style', 'border-inline-width', 'border-left',
        'border-left-color', 'border-left-style', 'border-left-width', 'border-radius', 'border-right',
        'border-right-color', 'border-right-style', 'border-right-width', 'border-spacing',
        'border-start-end-radius', 'border-start-start-radius', 'border-style', 'border-top',
        'border-top-color', 'border-top-left-radius', 'border-top-right-radius', 'border-top-style',
        'border-top-width', 'border-width', 'bottom', 'box-decoration-break', 'box-shadow',
        'box-sizing', 'break-after', 'break-before', 'break-inside', 'caption-side', 'caret-color',
        'clear', 'clip', 'clip-path', 'color', 'color-scheme', 'column-count', 'column-fill',
        'column-gap', 'column-rule', 'column-rule-color', 'column-rule-style', 'column-rule-width',
        'column-span', 'column-width', 'columns', 'content', 'counter-increment', 'counter-reset',
        'cursor', 'direction', 'display', 'empty-cells', 'filter', 'flex', 'flex-basis',
        'flex-direction', 'flex-flow', 'flex-grow', 'flex-shrink', 'flex-wrap', 'float', 'font',
        'font-family', 'font-feature-settings', 'font-kerning', 'font-size', 'font-size-adjust',
        'font-stretch', 'font-style', 'font-variant', 'font-variant-ligatures',
        'font-variant-numeric', 'font-variation-settings', 'font-weight', 'gap', 'grid', 'grid-area',
        'grid-auto-columns', 'grid-auto-flow', 'grid-auto-rows', 'grid-column', 'grid-column-end',
        'grid-column-start', 'grid-gap', 'grid-row', 'grid-row-end', 'grid-row-start', 'grid-template',
        'grid-template-areas', 'grid-template-columns', 'grid-template-rows', 'hanging-punctuation',
        'height', 'hyphens', 'image-rendering', 'inline-size', 'inset', 'inset-block',
        'inset-block-end', 'inset-block-start', 'inset-inline', 'inset-inline-end',
        'inset-inline-start', 'isolation', 'justify-content', 'justify-items', 'justify-self', 'left',
        'letter-spacing', 'line-break', 'line-height', 'list-style', 'list-style-image',
        'list-style-position', 'list-style-type', 'margin', 'margin-block', 'margin-block-end',
        'margin-block-start', 'margin-bottom', 'margin-inline', 'margin-inline-end',
        'margin-inline-start', 'margin-left', 'margin-right', 'margin-top', 'mask', 'mask-image',
        'mask-position', 'mask-repeat', 'mask-size', 'max-block-size', 'max-height', 'max-inline-size',
        'max-width', 'min-block-size', 'min-height', 'min-inline-size', 'min-width', 'mix-blend-mode',
        'object-fit', 'object-position', 'offset', 'opacity', 'order', 'orphans', 'outline',
        'outline-color', 'outline-offset', 'outline-style', 'outline-width', 'overflow',
        'overflow-anchor', 'overflow-wrap', 'overflow-x', 'overflow-y', 'overscroll-behavior',
        'padding', 'padding-block', 'padding-block-end', 'padding-block-start', 'padding-bottom',
        'padding-inline', 'padding-inline-end', 'padding-inline-start', 'padding-left', 'padding-right',
        'padding-top', 'page-break-after', 'page-break-before', 'page-break-inside', 'perspective',
        'perspective-origin', 'place-content', 'place-items', 'place-self', 'pointer-events',
        'position', 'quotes', 'resize', 'right', 'rotate', 'row-gap', 'scale', 'scroll-behavior',
        'scroll-margin', 'scroll-padding', 'scroll-snap-align', 'scroll-snap-type', 'shape-outside',
        'tab-size', 'table-layout', 'text-align', 'text-align-last', 'text-decoration',
        'text-decoration-color', 'text-decoration-line', 'text-decoration-style',
        'text-decoration-thickness', 'text-indent', 'text-overflow', 'text-shadow', 'text-transform',
        'text-underline-offset', 'text-wrap', 'top', 'touch-action', 'transform', 'transform-origin',
        'transform-style', 'transition', 'transition-delay', 'transition-duration',
        'transition-property', 'transition-timing-function', 'translate', 'unicode-bidi',
        'user-select', 'vertical-align', 'visibility', 'white-space', 'widows', 'width',
        'will-change', 'word-break', 'word-spacing', 'word-wrap', 'writing-mode', 'z-index',
    ];

    private const KEYS = ['when', 'target', 'child', 'nth', 'state', 'pseudo', 'css'];

    private const CLASS_BODY = '\.nc-[a-z0-9]+(?:-[a-z0-9]+)*(?:__[a-z0-9-]+)?(?:--[a-z0-9-]+)?';
    private const CLASS_NAME = '/^' . self::CLASS_BODY . '$/D';
    private const CUSTOM_PROPERTY = '/^--[a-z][a-z0-9-]{0,60}$/D';
    private const INTEGER = '/^-?\d+$/D';
    private const AN_PLUS_B = '/^-?\d*n(?:[+-]\d+)?$/D';

    /** Vero per una proprietà standard o una custom property (--nome). */
    public static function isProperty(string $name): bool
    {
        return in_array($name, self::PROPERTIES, true) || preg_match(self::CUSTOM_PROPERTY, $name) === 1;
    }

    /**
     * Controlla solo la struttura di style.custom; i valori delle dichiarazioni non si controllano.
     * @return list<ValidationError>
     */
    public static function errors(mixed $custom, string $base): array
    {
        if (!is_array($custom) || !array_is_list($custom)) {
            return [new ValidationError(
                path: $base,
                message: 'style.custom must be a list of rules',
                fix: 'use a list of at most ' . self::MAX_RULES . ' rules, each an object with css',
            )];
        }
        if (count($custom) > self::MAX_RULES) {
            return [new ValidationError(
                path: $base,
                message: 'too many rules',
                got: count($custom),
                fix: 'use at most ' . self::MAX_RULES . ' rules',
            )];
        }

        $errors = [];
        foreach ($custom as $i => $rule) {
            $at = $base . '/' . $i;
            if (!is_array($rule) || array_is_list($rule)) {
                $errors[] = new ValidationError(
                    path: $at,
                    message: 'rule must be an object',
                    fix: 'use an object with css and optional keys: ' . implode(', ', self::KEYS),
                );
                continue;
            }

            foreach (array_keys($rule) as $key) {
                if (!in_array($key, self::KEYS, true)) {
                    $errors[] = self::invalid($at . '/' . $key, 'unknown key', $key, self::KEYS);
                }
            }

            if (array_key_exists('when', $rule) && !self::isState($rule['when'])) {
                $errors[] = self::invalid($at . '/when', 'unknown state', $rule['when'], array_keys(self::STATES));
            }

            if (array_key_exists('target', $rule) && !self::isTarget($rule['target'])) {
                $errors[] = self::invalid(
                    $at . '/target',
                    'unknown target',
                    $rule['target'],
                    [...self::ELEMENTS, '.nc-<name>'],
                );
            }

            if (array_key_exists('child', $rule) && !is_bool($rule['child'])) {
                $errors[] = new ValidationError(
                    path: $at . '/child',
                    message: 'child must be a boolean',
                    got: $rule['child'],
                    fix: 'use true (direct child, >) or false (descendant)',
                );
            }

            if (array_key_exists('nth', $rule) && !self::isNth($rule['nth'])) {
                $errors[] = self::invalid(
                    $at . '/nth',
                    'unknown nth',
                    $rule['nth'],
                    ['first', 'last', 'odd', 'even', 'integer', 'an+b'],
                );
            }

            if (array_key_exists('state', $rule)) {
                if (!self::isState($rule['state'])) {
                    $errors[] = self::invalid($at . '/state', 'unknown state', $rule['state'], array_keys(self::STATES));
                }
                if (!array_key_exists('target', $rule)) {
                    $errors[] = new ValidationError(
                        path: $at . '/state',
                        message: 'state needs a target',
                        fix: 'add a target, or use when for the node itself',
                    );
                }
            }

            if (array_key_exists('pseudo', $rule) && !in_array($rule['pseudo'], self::PSEUDOS, true)) {
                $errors[] = self::invalid($at . '/pseudo', 'unknown pseudo', $rule['pseudo'], self::PSEUDOS);
            }

            $css = $rule['css'] ?? null;
            if (!is_array($css) || array_is_list($css)) {
                $errors[] = new ValidationError(
                    path: $at . '/css',
                    message: 'css must be an object with at least one property',
                    fix: 'add at least one declaration, e.g. {"color": "#111"}',
                );
                continue;
            }
            foreach (array_keys($css) as $prop) {
                if (!is_string($prop) || !self::isProperty($prop)) {
                    $errors[] = new ValidationError(
                        path: $at . '/css/' . $prop,
                        message: 'unknown CSS property',
                        got: $prop,
                        fix: 'use a standard CSS property in kebab-case (background-color) or a custom property (--name)',
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Selettore CSS di una regola già validata da errors(); $self è la classe del nodo (es. ".nc-x1").
     * @param array<string,mixed> $rule
     */
    public static function selector(array $rule, string $self): string
    {
        $css = $self;
        if (isset($rule['when'])) {
            $css .= self::STATES[$rule['when']];
        }
        if (isset($rule['target'])) {
            $css .= (($rule['child'] ?? false) === true ? '>' : ' ') . $rule['target'];
        }
        if (isset($rule['nth'])) {
            $css .= self::nthSuffix((string) $rule['nth']);
        }
        if (isset($rule['state'])) {
            $css .= self::STATES[$rule['state']];
        }
        if (isset($rule['pseudo'])) {
            $css .= '::' . $rule['pseudo'];
        }

        return $css;
    }

    /**
     * Converte la forma vecchia (selettore → proprietà → valore). Un selettore fuori dalla grammatica si scarta.
     * @param array<string,mixed> $legacy
     * @return list<array<string,mixed>>
     */
    public static function fromLegacy(array $legacy): array
    {
        $rules = [];
        foreach ($legacy as $selector => $declarations) {
            if (count($rules) === self::MAX_RULES) {
                break;
            }
            $rule = self::parseLegacy((string) $selector, $declarations);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /** @return array<string,mixed>|null */
    private static function parseLegacy(string $selector, mixed $declarations): ?array
    {
        if (!is_array($declarations) || preg_match(self::legacyPattern(), $selector, $m) !== 1) {
            return null;
        }

        $rule = [];
        $when = $m['when'] ?? '';
        if ($when !== '') {
            $name = array_search($when, self::STATES, true);
            if ($name === false) {
                return null;
            }
            $rule['when'] = $name;
        }

        $target = $m['target'] ?? '';
        if ($target !== '') {
            $rule['target'] = $target;
            if (($m['child'] ?? '') === ' > ') {
                $rule['child'] = true;
            }

            $nth = $m['nth'] ?? '';
            if ($nth !== '') {
                $rule['nth'] = match ($nth) {
                    ':first-child' => 'first',
                    ':last-child' => 'last',
                    default => substr($nth, 11, -1),
                };
            }

            $state = $m['state'] ?? '';
            if ($state !== '') {
                $name = array_search($state, self::STATES, true);
                if ($name === false) {
                    return null;
                }
                $rule['state'] = $name;
            }
        }

        $pseudo = ($m['pseudo'] ?? '') . ($m['legacyPseudo'] ?? '');
        if ($pseudo !== '') {
            if (!in_array($pseudo, self::PSEUDOS, true)) {
                return null;
            }
            $rule['pseudo'] = $pseudo;
        }

        $css = [];
        foreach ($declarations as $prop => $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $css[(string) $prop] = $value;
            }
        }
        if ($css === []) {
            return null;
        }
        $rule['css'] = $css;

        return $rule;
    }

    /** Grammatica dei selettori vecchi: & + when + (' > ' o ' ') + target + nth + state + pseudo. */
    private static function legacyPattern(): string
    {
        $states = self::alternation(array_values(self::STATES));
        $elements = self::alternation(self::ELEMENTS) . '(?![a-z0-9])';
        $target = '(?:' . $elements . '|' . self::CLASS_BODY . ')';
        $nth = ':first-child|:last-child|:nth-child\((?:odd|even|-?\d+|-?\d*n(?:[+-]\d+)?)\)';

        return '#^&(?P<when>' . $states . '*)'
            . '(?:(?P<child> > | )(?P<target>' . $target . ')(?P<nth>' . $nth . ')?(?P<state>' . $states . '*))?'
            . '(?:::(?P<pseudo>[a-z-]+)|:(?P<legacyPseudo>before|after))?$#D';
    }

    /** @param list<string> $items */
    private static function alternation(array $items): string
    {
        return '(?:' . implode('|', array_map(static fn (string $s): string => preg_quote($s, '#'), $items)) . ')';
    }

    private static function nthSuffix(string $nth): string
    {
        return match ($nth) {
            'first' => ':first-child',
            'last' => ':last-child',
            default => ':nth-child(' . $nth . ')',
        };
    }

    private static function isState(mixed $state): bool
    {
        return is_string($state) && array_key_exists($state, self::STATES);
    }

    private static function isTarget(mixed $target): bool
    {
        return is_string($target)
            && (in_array($target, self::ELEMENTS, true) || preg_match(self::CLASS_NAME, $target) === 1);
    }

    private static function isNth(mixed $nth): bool
    {
        if (is_int($nth)) {
            return true;
        }

        return is_string($nth)
            && (in_array($nth, ['first', 'last', 'odd', 'even'], true)
                || preg_match(self::INTEGER, $nth) === 1
                || preg_match(self::AN_PLUS_B, $nth) === 1);
    }

    /** @param list<mixed> $allowed */
    private static function invalid(string $path, string $message, mixed $got, array $allowed): ValidationError
    {
        return new ValidationError(
            path: $path,
            message: $message,
            got: $got,
            allowed: $allowed,
            fix: 'use one of: ' . implode(', ', $allowed),
        );
    }
}
