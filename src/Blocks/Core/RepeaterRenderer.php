<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

use Nucleo\Bindings\BindingResolver;
use Nucleo\Blocks\CardRules;
use Nucleo\Blocks\DynamicRenderer;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\NodeValidator;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;
use Nucleo\Runtime\CardRenderer;
use Nucleo\Runtime\Loop;
use Nucleo\Schema\ValidationError;
use WP_Post;

/**
 * nc/repeater: ripete il figlio (la riga) per ogni riga di un campo ripetitore del modello del post corrente.
 * Lo slot legge le righe a ogni richiesta; senza righe stampa il testo "empty", se c'è.
 */
final class RepeaterRenderer implements Renderer, DynamicRenderer, NodeValidator
{
    private const TAGS = ['div', 'ul', 'ol'];

    public function render(Node $node, RenderContext $ctx): string
    {
        $card = $node->children[0] ?? null;
        $template = $card === null ? ['html' => '', 'slots' => []] : $ctx->template($card);

        return $ctx->slot('nc/repeater', [
            'class' => trim($ctx->classes($node)),
            'tag' => $this->tag($node->settings['tag'] ?? null),
            'field' => $this->str($node->settings['field'] ?? null, ''),
            'empty' => $this->str($node->settings['empty'] ?? null, ''),
            'template' => $template,
        ], '');
    }

    /** @param array<string,mixed> $data */
    public function renderSlot(array $data): string
    {
        $class = esc_attr($this->str($data['class'] ?? null, ''));
        $tag = $this->tag($data['tag'] ?? null);
        $empty = $this->str($data['empty'] ?? null, '');
        $field = $this->str($data['field'] ?? null, '');
        $template = is_array($data['template'] ?? null) ? $data['template'] : ['html' => '', 'slots' => []];

        $post = Loop::post() ?? get_queried_object();
        $rows = $post instanceof WP_Post && $field !== '' ? BindingResolver::rows($post->ID, $field) : null;
        if ($rows === null || $rows === []) {
            return $empty === '' ? '' : '<p class="' . $class . '">' . esc_html($empty) . '</p>';
        }

        $items = '';
        foreach ($rows as $i => $row) {
            // index non sostituisce un sottocampo con lo stesso nome.
            $row += ['index' => (string) ($i + 1)];
            $card = CardRenderer::render($template, $row);
            $items .= $tag === 'div' ? $card : '<li class="nc-repeater__item">' . $card . '</li>';
        }

        return '<' . $tag . ' class="' . $class . '">' . $items . '</' . $tag . '>';
    }

    public function validateNode(array $node, string $path): array
    {
        $children = is_array($node['children'] ?? null) ? array_values($node['children']) : [];
        $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
        $errors = [];

        if (count($children) !== 1) {
            $errors[] = new ValidationError(
                "$path/children",
                __('nc/repeater needs exactly one child: the row', 'nucleo'),
                count($children),
                [],
                __('add one node: it is repeated for each row', 'nucleo'),
                'card_missing',
            );
        }
        if (is_array($children[0] ?? null)) {
            array_push($errors, ...CardRules::errors($children[0], "$path/children/0"));
            $nested = self::nestedRepeater($children[0], "$path/children/0");
            if ($nested !== null) {
                $errors[] = new ValidationError(
                    $nested,
                    __('nc/repeater inside a repeater row is not allowed: it would repeat the rows of the post again', 'nucleo'),
                    'nc/repeater',
                    [],
                    __('use one nc/repeater per repeater field, outside the other rows', 'nucleo'),
                    'card_block_not_allowed',
                );
            }
        }

        $field = $settings['field'] ?? null;
        if (!is_string($field) || trim($field) === '') {
            $errors[] = new ValidationError(
                "$path/settings/field",
                __('repeater field not set', 'nucleo'),
                $field,
                [],
                __('use the key of a repeater field of the post type', 'nucleo'),
                'field_missing',
            );
        }

        return $errors;
    }

    /**
     * Percorso del primo nc/repeater nel sottoalbero della riga, null se non ce n'è.
     * @param array<string,mixed> $node
     */
    private static function nestedRepeater(array $node, string $path): ?string
    {
        if (($node['type'] ?? null) === 'nc/repeater') {
            return "$path/type";
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $i => $child) {
            $hit = is_array($child) ? self::nestedRepeater($child, "$path/children/$i") : null;
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    private function tag(mixed $value): string
    {
        return is_string($value) && in_array($value, self::TAGS, true) ? $value : 'div';
    }

    private function str(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }
}
