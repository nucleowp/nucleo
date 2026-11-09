<?php

declare(strict_types=1);

namespace Nucleo\Bindings;

use Nucleo\Blocks\DynamicRenderer;
use Nucleo\Runtime\Loop;

/**
 * Slot nc/bind: sostituisce i token del nodo con i valori letti nella richiesta.
 * Valore vuoto = fallback. Ogni valore è escapato per il suo uso: testo, URL o HTML sanificato.
 */
final class BindSlot implements DynamicRenderer
{
    public function renderSlot(array $data): string
    {
        $html = $data['html'] ?? null;
        $binds = $data['binds'] ?? null;
        if (!is_string($html) || !is_array($binds)) {
            return '';
        }

        $tokens = [];
        $values = [];
        foreach ($binds as $bind) {
            if (!is_array($bind)) {
                continue;
            }
            $token = $bind['token'] ?? null;
            $source = $bind['source'] ?? null;
            $field = $bind['field'] ?? null;
            $fallback = $bind['fallback'] ?? null;
            $escape = $bind['escape'] ?? null;
            if (
                !is_string($token) || !is_string($source) || !is_string($field)
                || !is_string($fallback) || !is_string($escape)
            ) {
                continue;
            }

            $value = self::read($source, $field);
            if ($value === '') {
                $value = $fallback;
            }

            $tokens[] = $token;
            $values[] = match ($escape) {
                'url' => esc_url($value),
                'html' => wp_kses_post($value),
                default => esc_html($value),
            };
        }

        return str_replace($tokens, $values, $html);
    }

    /**
     * Valore di un campo per il nodo corrente: dentro una scheda l'elemento corrente, fuori l'oggetto della richiesta.
     * '' se il valore manca.
     */
    public static function read(string $source, string $field): string
    {
        // Dentro una scheda vale l'elemento corrente; fuori, l'oggetto della richiesta.
        if ($source === 'item') {
            return self::itemValue(Loop::item(), $field) ?? '';
        }
        if ($source === 'term') {
            // Dentro una scheda di termini vale il termine della scheda; fuori, il termine interrogato. Mai un post.
            $term = Loop::term() ?? get_queried_object();

            return $term instanceof \WP_Term ? (BindingResolver::value('term', $field, $term->term_id) ?? '') : '';
        }
        $objectId = Loop::post()->ID ?? get_queried_object_id();

        return BindingResolver::value($source, $field, $objectId) ?? '';
    }

    /** @param array<string,mixed>|null $item */
    private static function itemValue(?array $item, string $field): ?string
    {
        $value = $item[$field] ?? null;

        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }
}
