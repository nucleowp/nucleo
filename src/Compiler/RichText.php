<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/** Sanitizes inline rich text: keeps a short list of tags and attributes, unwraps the other tags. */
final class RichText
{
    /** tag → attributes allowed on it */
    private const ALLOWED = [
        'strong' => [],
        'em' => [],
        'b' => [],
        'i' => [],
        'br' => [],
        'p' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'span' => ['class'],
        'a' => ['href', 'target', 'rel'],
    ];

    /** Tags dropped together with their content. */
    private const DROP = ['script', 'style'];

    public static function clean(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // the wrapper keeps libxml from inserting an implied <p> around bare text; it is unwrapped below
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return '';
        }

        self::sanitize($doc);

        $out = '';
        foreach ($doc->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private static function sanitize(DOMNode $parent): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMText) {
                continue;
            }
            if (!$child instanceof DOMElement) {
                // comments and processing instructions
                $parent->removeChild($child);
                continue;
            }

            $tag = strtolower($child->nodeName);
            if (in_array($tag, self::DROP, true)) {
                $parent->removeChild($child);
                continue;
            }

            self::sanitize($child);

            if (!array_key_exists($tag, self::ALLOWED)) {
                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }

            self::filterAttributes($child, $tag);
        }
    }

    private static function filterAttributes(DOMElement $el, string $tag): void
    {
        $names = [];
        foreach ($el->attributes as $attr) {
            $names[] = $attr->nodeName;
        }

        foreach ($names as $name) {
            if (!in_array($name, self::ALLOWED[$tag], true)) {
                $el->removeAttribute($name);
            }
        }

        if ($tag !== 'a') {
            return;
        }

        if ($el->hasAttribute('href') && !self::safeHref($el->getAttribute('href'))) {
            $el->removeAttribute('href');
        }

        if (strtolower(trim($el->getAttribute('target'))) === '_blank') {
            $rel = preg_split('/\s+/', trim($el->getAttribute('rel')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $rel[] = 'noopener';
            $el->setAttribute('rel', implode(' ', array_unique($rel)));
        }
    }

    /** Allows http, https, mailto, tel, "/" (not protocol-relative) and "#". */
    private static function safeHref(string $href): bool
    {
        // browsers ignore control characters and spaces inside the scheme
        $probe = preg_replace('/[\x00-\x20\x7F]+/', '', $href) ?? '';

        return preg_match('#^(https?:|mailto:|tel:|/(?!/)|\#)#i', $probe) === 1;
    }
}
