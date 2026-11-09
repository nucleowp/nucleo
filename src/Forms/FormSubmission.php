<?php

declare(strict_types=1);

namespace Nucleo\Forms;

use WP_Post;

/** Un invio validato: valori dei campi (context compresi), post di contesto, documento e nodo. */
final class FormSubmission
{
    /** @param array<string,string|list<string>> $values */
    public function __construct(
        public readonly int $doc,
        public readonly FormConfig $config,
        public readonly array $values,
        public readonly ?WP_Post $context,
        public readonly string $ip,
    ) {
    }

    /**
     * Sostituisce i segnaposto {field:x}, {post.title}, {post.url}, {post.id}, {site.name}, {fields} e le chiavi di $extra ({code}).
     * Restituisce testo semplice.
     * @param array<string,string> $extra
     */
    public function fill(string $template, array $extra = []): string
    {
        $tokens = [
            'site.name' => (string) get_bloginfo('name'),
            'post.id' => $this->context === null ? '' : (string) $this->context->ID,
            'post.title' => $this->context === null ? '' : (string) $this->context->post_title,
            'post.url' => $this->context === null ? '' : (string) get_permalink($this->context),
            'fields' => $this->fieldLines(),
        ];
        foreach ($extra as $key => $value) {
            $tokens[$key] = $value;
        }

        // Una sola passata: i valori inseriti non vengono riletti come segnaposto.
        $text = preg_replace_callback(
            '/\{([^{}]+)\}/',
            function (array $match) use ($tokens): string {
                $key = $match[1];
                if (array_key_exists($key, $tokens)) {
                    return $tokens[$key];
                }
                if (str_starts_with($key, 'field:')) {
                    return $this->valueText(substr($key, 6));
                }

                return $match[0];
            },
            $template,
        );

        return (string) $text;
    }

    /** Valore di un campo come testo: liste unite con ", ", campo assente = stringa vuota. */
    private function valueText(string $name): string
    {
        $value = $this->values[$name] ?? '';

        return is_array($value) ? implode(', ', $value) : $value;
    }

    /** Righe "etichetta: valore" dei campi non context che hanno un valore. */
    private function fieldLines(): string
    {
        $lines = [];
        foreach ($this->config->fields as $field) {
            if ($field['type'] === 'context') {
                continue;
            }
            $text = $this->valueText((string) $field['name']);
            if ($text === '') {
                continue;
            }
            $label = $field['label'] !== '' ? $field['label'] : $field['name'];
            $lines[] = $label . ': ' . $text;
        }

        return implode("\n", $lines);
    }
}
