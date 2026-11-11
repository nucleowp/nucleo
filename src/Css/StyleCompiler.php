<?php

declare(strict_types=1);

namespace Nucleo\Css;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Node;
use Nucleo\Schema\Sanitizer;
use Nucleo\Tokens\TokenResolver;

/**
 * Compiles the responsive styles of a node tree into minified CSS and one class list per node.
 * Output: base rules, then one @media block per breakpoint, then one @container block per breakpoint
 * (for descendants of a responsiveMode "container" node), then the @keyframes of the motion presets used
 * and a prefers-reduced-motion rule for the nodes with motion. Nodes with the same normalized style share a class.
 */
final class StyleCompiler
{
    /** Separa il selettore dalla dichiarazione per le chiavi con selettore (PropertyMap::selector). */
    private const SELECTOR_SEP = "\x1F";



    /** Nessun caricamento di risorse esterne dai valori di style.custom. */
    private const CUSTOM_URL = '/url\s*\(/i';

    public function __construct(
        private readonly TokenResolver $tokens,
        private readonly Breakpoints $breakpoints,
        /** @var array<string,array<string,string>> Registry::partSelectors() */
        private readonly array $parts = [],
    ) {
    }

    /** @param string $scope prefisso delle classi per nodo: vuoto per le pagine, diverso per ogni layout */
    public function compile(Node $root, string $scope = ''): CssResult
    {
        $entries = [];
        $this->collect($root, false, $entries);

        $counts = array_count_values(array_column($entries, 'sig'));

        $classes = [];
        $rules = [];
        foreach ($entries as $entry) {
            $name = $counts[$entry['sig']] >= 2
                ? 'nc-h' . substr(sha1($entry['sig']), 0, 6)
                : 'nc-' . $scope . self::idPart((string) $entry['id']);
            $classes[$entry['id']] = [$name];
            $rules[$name] ??= $entry;
        }

        $css = '';
        foreach ($rules as $name => $entry) {
            if ($entry['decls']['base'] !== []) {
                $css .= self::rule($name, $entry['decls']['base']);
            }
            // Le regole custom seguono le regole base del nodo; & diventa la classe del nodo.
            foreach ($entry['custom'] as $selector => $decls) {
                $css .= str_replace('&', '.' . $name, (string) $selector) . '{' . implode(';', $decls) . '}';
            }
        }
        $hidden = '';

        foreach ([false, true] as $inContainer) {
            foreach (array_slice(Breakpoints::KEYS, 1) as $key) {
                $inner = '';
                foreach ($rules as $name => $entry) {
                    if ($entry['container'] === $inContainer && $entry['decls'][$key] !== []) {
                        $inner .= self::rule($name, $entry['decls'][$key]);
                    }
                }
                if ($inner === '') {
                    continue;
                }

                $minWidth = $this->breakpoints->minWidths[$key]
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                    ?? throw new \InvalidArgumentException("Missing min-width for breakpoint: $key");
                $at = $inContainer ? '@container' : '@media';
                $css .= $at . ' (min-width:' . $minWidth . 'px){' . $inner . '}';
            }
        }

        // Visibilità per breakpoint: dopo gli stili responsive, così display:none vince sul display del nodo.
        foreach ($rules as $name => $entry) {
            foreach ($entry['hide'] as [$from, $to]) {
                $hidden .= $this->hideRule($name, $from, $to, $entry['container']);
            }
        }
        $css .= $hidden;

        // Motion: keyframes dei soli preset usati, una volta ciascuno, poi la regola reduced-motion.
        $presets = [];
        $reduced = '';
        foreach ($rules as $name => $entry) {
            // Una regola reduced-motion per selettore con motion: la classe del nodo e ogni sua parte.
            $motionSelectors = [];
            foreach ($entry['decls'] as $list) {
                foreach ($list as $declaration) {
                    $body = self::body($declaration);
                    if (Motion::isDeclaration($body)) {
                        $motionSelectors[self::selectorOf($declaration)] = true;
                    }
                    $preset = Motion::presetOf($body);
                    if ($preset !== null) {
                        $presets[] = $preset;
                    }
                }
            }
            foreach (array_keys($motionSelectors) as $selector) {
                $reduced .= self::rule($name, [
                    $selector . self::SELECTOR_SEP . 'animation:none',
                    $selector . self::SELECTOR_SEP . 'transition:none',
                ]);
            }
        }
        if ($presets !== []) {
            $css .= Motion::keyframes($presets);
        }
        if ($reduced !== '') {
            $css .= '@media (prefers-reduced-motion:reduce){' . $reduced . '}';
        }

        return new CssResult($classes, $css);
    }

    /**
     * Depth-first, document order. Appends an entry for each node that has declarations.
     *
     * @param list<array{id:string,sig:string,container:bool,decls:array<string,list<string>>,hide:list<array{0:string,1:?string}>,custom:array<string,list<string>>}> $entries
     */
    private function collect(Node $node, bool $inContainer, array &$entries): void
    {
        $decls = array_fill_keys(Breakpoints::KEYS, []);
        $style = $node->style;
        unset($style['parts'], $style['custom']);
        $this->addDeclarations($style, null, $decls);

        // Le parti: solo quelle dichiarate per il tipo di blocco; il selettore della parte entra nelle dichiarazioni.
        $partSelectors = $this->parts[$node->type] ?? [];
        $partStyles = $node->style['parts'] ?? [];
        if (is_array($partStyles)) {
            foreach ($partStyles as $part => $partStyle) {
                if (is_array($partStyle) && isset($partSelectors[$part])) {
                    $this->addDeclarations($partStyle, $partSelectors[$part], $decls);
                }
            }
        }

        $isContainer = ($node->settings['responsiveMode'] ?? null) === 'container';
        if ($isContainer) {
            $decls['base'][] = 'container-type:inline-size';
        }

        // Le dichiarazioni motion restano in fondo a ogni blocco, anche dopo container-type.
        foreach ($decls as $key => $list) {
            $decls[$key] = array_merge(
                array_values(array_filter($list, static fn (string $d): bool => !Motion::isDeclaration(self::body($d)))),
                array_values(array_filter($list, static fn (string $d): bool => Motion::isDeclaration(self::body($d)))),
            );
        }

        $custom = $this->customRules($node->style['custom'] ?? null);
        $hide = self::hiddenRanges($node->visibility['breakpoints'] ?? null);
        if (array_filter($decls) !== [] || $hide !== [] || $custom !== []) {
            $entries[] = [
                'id' => $node->id,
                'sig' => self::signature($decls, $inContainer)
                    . ($hide === [] ? '' : '|hide' . wp_json_encode($hide))
                    . ($custom === [] ? '' : '|custom' . wp_json_encode($custom)),
                'container' => $inContainer,
                'decls' => $decls,
                'hide' => $hide,
                'custom' => $custom,
            ];
        }

        foreach ($node->children as $child) {
            $this->collect($child, $inContainer || $isContainer, $entries);
        }
    }

    /**
     * Dichiarazioni di uno stile nei breakpoint: quello del nodo (partSelector null) o quello di una parte.
     * Nelle parti contano solo i gruppi di PART_GROUPS; le altre chiavi si ignorano.
     *
     * @param array<array-key,mixed> $style
     * @param array<string,list<string>> $decls
     */
    private function addDeclarations(array $style, ?string $partSelector, array &$decls): void
    {
        uksort($style, fn (int|string $a, int|string $b): int => self::motionOrder((string) $a) <=> self::motionOrder((string) $b));
        foreach ($style as $prop => $raw) {
            $prop = (string) $prop;
            if ($partSelector !== null && !in_array(PropertyMap::group($prop), PropertyMap::PART_GROUPS, true)) {
                continue;
            }
            if (is_array($raw)) {
                foreach (Breakpoints::KEYS as $key) {
                    if (array_key_exists($key, $raw)) {
                        $decls[$key] = array_merge($decls[$key], $this->declare($prop, $raw[$key], $partSelector));
                    }
                }
            } else {
                $decls['base'] = array_merge($decls['base'], $this->declare($prop, $raw, $partSelector));
            }
        }
    }

    /**
     * style.custom validato: ogni regola diventa un selettore costruito da CustomRules, con & al posto della classe del nodo.
     * Una lista non valida si scarta intera; una dichiarazione con valore non valido si scarta da sola.
     *
     * @return array<string,list<string>> selettore (con &) → dichiarazioni "proprietà:valore"
     */
    private function customRules(mixed $custom): array
    {
        if (!is_array($custom) || $custom === [] || CustomRules::errors($custom, '') !== []) {
            return [];
        }

        $rules = [];
        foreach ($custom as $rule) {
            $selector = CustomRules::selector($rule, '&');
            foreach ($rule['css'] as $prop => $raw) {
                $value = $this->customValue($raw);
                if ($value !== null) {
                    $rules[$selector][] = $prop . ':' . $value;
                }
            }
        }

        return $rules;
    }

    /** Valore di style.custom: il numero com'è, la stringa con i token risolti e controllata come le altre dichiarazioni. */
    private function customValue(mixed $raw): ?string
    {
        if (is_int($raw) || is_float($raw)) {
            return is_finite((float) $raw) ? (string) $raw : null;
        }
        if (!is_string($raw)) {
            return null;
        }

        $resolved = $this->resolveParts($raw);
        if ($resolved === null || !Sanitizer::cssValue($resolved) || preg_match(self::CUSTOM_URL, $resolved) === 1) {
            return null;
        }

        return $resolved;
    }

    /**
     * Intervalli di breakpoint in cui il nodo è nascosto: [primo breakpoint, breakpoint da cui torna visibile o null].
     * Un breakpoint senza valore eredita dal precedente; base è visibile se non indicato.
     *
     * @return list<array{0:string,1:?string}>
     */
    private static function hiddenRanges(mixed $breakpoints): array
    {
        if (!is_array($breakpoints)) {
            return [];
        }
        $ranges = [];
        $visible = true;
        $start = null;
        foreach (Breakpoints::KEYS as $key) {
            $value = $breakpoints[$key] ?? null;
            if (is_bool($value)) {
                $visible = $value;
            }
            if (!$visible && $start === null) {
                $start = $key;
            } elseif ($visible && $start !== null) {
                $ranges[] = [$start, $key];
                $start = null;
            }
        }
        if ($start !== null) {
            $ranges[] = [$start, null];
        }

        return $ranges;
    }

    /** display:none per la classe tra due breakpoint; senza limiti è una regola semplice. */
    private function hideRule(string $name, string $from, ?string $to, bool $inContainer): string
    {
        $conditions = [];
        if ($from !== 'base') {
            $conditions[] = '(min-width:' . $this->minWidth($from) . 'px)';
        }
        if ($to !== null) {
            $conditions[] = '(max-width:' . ($this->minWidth($to) - 0.02) . 'px)';
        }
        $rule = self::rule($name, ['display:none!important']);
        if ($conditions === []) {
            return $rule;
        }

        return ($inContainer ? '@container ' : '@media ') . implode(' and ', $conditions) . '{' . $rule . '}';
    }

    private function minWidth(string $key): int
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
        return $this->breakpoints->minWidths[$key] ?? throw new \InvalidArgumentException("Missing min-width for breakpoint: $key");
    }

    /**
     * Gli id senza cifre (menu, hero) potrebbero coincidere con la classe di un blocco (.nc-menu): prendono il prefisso n-.
     * Le classi dei blocchi non contengono cifre né iniziano con n-.
     */
    private static function idPart(string $id): string
    {
        return preg_match('/\d/', $id) === 1 ? $id : 'n-' . $id;
    }

    /** @return list<string> */
    private function declare(string $prop, mixed $raw, ?string $partSelector = null): array
    {
        if (is_string($raw)) {
            $resolved = $this->resolveParts($raw);
            // Stesso controllo della validazione anche in compilazione: un documento salvato prima di una regola nuova
            // (per esempio "calc(1px") non porta il valore nel CSS.
            if ($resolved === null || !Sanitizer::cssValue($resolved)) {
                return [];
            }
            $raw = $resolved;
        } elseif (!is_int($raw) && !is_float($raw)) {
            return [];
        }

        $selector = $partSelector === null ? PropertyMap::selector($prop) : self::partSelector($partSelector, $prop);
        $declarations = PropertyMap::declarations($prop, $raw);

        // Le chiavi con selettore portano il selettore nella dichiarazione: rule() le separa.
        return $selector === '' ? $declarations : array_map(static fn (string $d): string => $selector . self::SELECTOR_SEP . $d, $declarations);
    }

    /**
     * Selettore di una chiave dentro una parte. Uno stato va prima del pseudo-elemento della parte:
     * " legend::before" + Hover → " legend:is(:hover,:focus-within)::before".
     */
    private static function partSelector(string $part, string $prop): string
    {
        $own = PropertyMap::selector($prop);
        if (PropertyMap::baseKey($prop) === $prop) {
            return $part . $own;
        }

        $at = strpos($part, '::');

        return $at === false ? $part . $own : substr($part, 0, $at) . $own . substr($part, $at);
    }

    /**
     * Risolve i token anche nei valori composti ("$space.12 0", "1px solid $color.border", "0 1px $color.text, $shadow.md").
     * Null se un token è sconosciuto: la dichiarazione si scarta.
     */
    private function resolveParts(string $raw): ?string
    {
        if (!str_contains($raw, '$')) {
            return $raw;
        }
        $unknown = false;
        $resolved = preg_replace_callback(
            '/\$[a-z]+\.[a-z0-9-]+/',
            function (array $m) use (&$unknown): string {
                $value = $this->tokens->resolve($m[0]);
                $unknown = $unknown || $value === null;

                return $value ?? '';
            },
            trim($raw),
        );

        return $unknown || $resolved === null ? null : (string) preg_replace('/\s+/', ' ', $resolved);
    }

    /** @param array<string,list<string>> $decls */
    private static function signature(array $decls, bool $inContainer): string
    {
        $parts = [];
        foreach (Breakpoints::KEYS as $key) {
            $sorted = $decls[$key];
            sort($sorted, SORT_STRING);
            $context = $key !== 'base' && $inContainer ? 'c' : 'm';
            $parts[] = $key . $context . '[' . implode(';', $sorted) . ']';
        }

        return implode('|', $parts);
    }

    /** Posizione di una chiave motion nell'ordine di scrittura; 0 per le altre. */
    private static function motionOrder(string $prop): int
    {
        $index = array_search($prop, Motion::ORDER, true);

        return $index === false ? 0 : 1 + $index;
    }

    /** Dichiarazione senza il selettore della parte davanti (vedi SELECTOR_SEP). */
    private static function body(string $declaration): string
    {
        $parts = explode(self::SELECTOR_SEP, $declaration, 2);

        return $parts[count($parts) - 1];
    }

    /** Selettore della parte davanti alla dichiarazione; vuoto per lo stile del nodo. */
    private static function selectorOf(string $declaration): string
    {
        $parts = explode(self::SELECTOR_SEP, $declaration, 2);

        return count($parts) === 2 ? $parts[0] : '';
    }

    /**
     * Regola della classe; le dichiarazioni con selettore diventano regole sui discendenti, nell'ordine di comparsa.
     * @param list<string> $decls
     */
    private static function rule(string $name, array $decls): string
    {
        $groups = [];
        foreach ($decls as $declaration) {
            $parts = explode(self::SELECTOR_SEP, $declaration, 2);
            [$selector, $body] = count($parts) === 2 ? $parts : ['', $parts[0]];
            $groups[$selector][] = $body;
        }
        $css = '';
        foreach ($groups as $selector => $bodies) {
            $css .= '.' . $name . $selector . '{' . implode(';', $bodies) . '}';
        }

        return $css;
    }
}
