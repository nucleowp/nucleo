<?php

declare(strict_types=1);

namespace Nucleo\Site;

use DOMDocument;
use DOMElement;
use WP_Error;

/** Icone SVG del sito, nell'opzione nucleo_icons: nome → SVG sanificato. I nomi del set interno sono riservati. */
final class SiteIcons
{
    private const OPTION = 'nucleo_icons';

    /** Nome di un'icona: minuscole, cifre e trattini, inizia con una lettera. */
    private const NAME_PATTERN = '/^[a-z][a-z0-9-]{0,40}$/D';

    private const MAX_ICONS = 300;

    /** Byte massimi di un SVG prima della sanificazione. */
    private const MAX_BYTES = 20000;

    /** Elementi ammessi: qualunque altro elemento, con i suoi figli, si scarta. */
    private const ELEMENTS = ['svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon'];

    /** Attributi ammessi su ogni elemento. */
    private const ATTRIBUTES = [
        'viewBox', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'width', 'height', 'points', 'transform',
        'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'fill-rule', 'clip-rule', 'opacity',
    ];

    /** Sulla radice svg restano viewBox e gli attributi di presentazione. */
    private const ROOT_ATTRIBUTES = [
        'viewBox', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'fill-rule', 'clip-rule', 'opacity',
    ];

    /** Colori: "none" resta, ogni altro valore diventa currentColor. */
    private const PAINT = ['fill', 'stroke'];

    /** @return array<string,string> */
    public static function all(): array
    {
        // Fuori da WordPress (compilazione nei test unitari) non ci sono icone del sito.
        if (!function_exists('get_option')) {
            return [];
        }
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return [];
        }

        $icons = [];
        foreach ($stored as $name => $svg) {
            if (is_string($name) && is_string($svg)) {
                $icons[$name] = $svg;
            }
        }

        return $icons;
    }

    /** Controlla il valore di icons di site.json: null se valido, altrimenti WP_Error invalid_input. */
    public static function check(mixed $icons): ?WP_Error
    {
        if (!is_array($icons)) {
            return new WP_Error('invalid_input', __('"icons" must be an object from icon name to SVG.', 'nucleo'));
        }
        if (count($icons) > self::MAX_ICONS) {
            /* translators: %d: maximum number of site icons. */
            return new WP_Error('invalid_input', sprintf(__('At most %d site icons are allowed.', 'nucleo'), self::MAX_ICONS));
        }

        foreach ($icons as $name => $svg) {
            $name = (string) $name;
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                /* translators: %s: icon name. */
                return new WP_Error('invalid_input', sprintf(__('Icon name "%s" must start with a letter and use only lowercase letters, digits and hyphens (max 41 characters).', 'nucleo'), $name));
            }
            if (!is_string($svg)) {
                /* translators: %s: icon name. */
                return new WP_Error('invalid_input', sprintf(__('Icon "%s" must be an SVG string.', 'nucleo'), $name));
            }
            if (strlen($svg) > self::MAX_BYTES) {
                /* translators: 1: icon name, 2: maximum size in bytes. */
                return new WP_Error('invalid_input', sprintf(__('Icon "%1$s" is larger than %2$d bytes.', 'nucleo'), $name, self::MAX_BYTES));
            }
            if (self::sanitize($svg) === null) {
                /* translators: %s: icon name. */
                return new WP_Error('invalid_input', sprintf(__('Icon "%s" must be an SVG with a viewBox and at least one element.', 'nucleo'), $name));
            }
        }

        return null;
    }

    /**
     * Salva il set di icone sanificate; un set vuoto cancella l'opzione.
     * @param array<string,mixed> $icons
     */
    public static function save(array $icons): void
    {
        $clean = [];
        foreach ($icons as $name => $svg) {
            $sanitized = is_string($svg) ? self::sanitize($svg) : null;
            if (is_string($name) && $sanitized !== null) {
                $clean[$name] = $sanitized;
            }
        }

        if ($clean === []) {
            delete_option(self::OPTION);

            return;
        }

        update_option(self::OPTION, $clean, false);
    }

    /**
     * Aggiunge o sostituisce le icone di $set e toglie quelle di $remove, lasciando le altre. Controlla il set risultante
     * prima di scrivere: con un errore non cambia nulla.
     * @param array<mixed> $set nome → SVG
     * @param array<mixed> $remove nomi
     * @return array<string,string>|WP_Error il set salvato
     */
    public static function update(array $set, array $remove): array|WP_Error
    {
        $icons = self::all();
        foreach ($remove as $name) {
            unset($icons[(string) $name]);
        }
        foreach ($set as $name => $svg) {
            $icons[(string) $name] = $svg;
        }

        $error = self::check($icons);
        if ($error !== null) {
            return $error;
        }
        self::save($icons);

        return self::all();
    }

    /** SVG di un'icona del sito, con $attrs (che inizia con uno spazio) dopo "<svg"; null se il nome non c'è. */
    public static function render(string $name, string $attrs): ?string
    {
        $svg = self::all()[$name] ?? null;
        if ($svg === null || !str_starts_with($svg, '<svg')) {
            return null;
        }

        return '<svg' . $attrs . ' width="24" height="24" focusable="false"' . substr($svg, 4);
    }

    /** SVG pulito, o null se non è un SVG con viewBox e almeno un elemento ammesso. */
    public static function sanitize(string $svg): ?string
    {
        // Niente DOCTYPE né entità: il parser non espande nulla.
        if (stripos($svg, '<!DOCTYPE') !== false || stripos($svg, '<!ENTITY') !== false) {
            return null;
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $loaded ? $doc->documentElement : null;
        if ($root === null || $root->localName !== 'svg' || trim($root->getAttribute('viewBox')) === '') {
            return null;
        }

        $body = self::children($root);
        if ($body === '') {
            return null;
        }

        return '<svg' . self::attributes($root, self::ROOT_ATTRIBUTES) . '>' . $body . '</svg>';
    }

    /** Figli ammessi di un elemento: testo e commenti non passano. */
    private static function children(DOMElement $el): string
    {
        $out = '';
        foreach ($el->childNodes as $node) {
            if ($node instanceof DOMElement && in_array($node->localName, self::ELEMENTS, true)) {
                $out .= self::element($node);
            }
        }

        return $out;
    }

    private static function element(DOMElement $el): string
    {
        $name = (string) $el->localName;
        $attrs = self::attributes($el, self::ATTRIBUTES);
        $body = self::children($el);

        return $body === '' ? "<$name$attrs/>" : "<$name$attrs>$body</$name>";
    }

    /**
     * @param list<string> $allowed
     */
    private static function attributes(DOMElement $el, array $allowed): string
    {
        $out = '';
        foreach ($el->attributes as $attr) {
            $name = $attr->name;
            if (!in_array($name, $allowed, true)) {
                continue;
            }
            $value = in_array($name, self::PAINT, true)
                ? (trim($attr->value) === 'none' ? 'none' : 'currentColor')
                : $attr->value;
            $out .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return $out;
    }
}
