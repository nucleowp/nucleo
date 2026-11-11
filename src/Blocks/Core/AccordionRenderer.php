<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

/** Basato su details/summary: nessuno script. */
final class AccordionRenderer implements Renderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        $openFirst = ($node->settings['openFirst'] ?? false) === true;

        $items = $node->content['items'] ?? [];
        $html = '';
        $index = 0;
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = is_string($item['title'] ?? null) ? $item['title'] : '';
            $body = is_string($item['html'] ?? null) ? $item['html'] : '';
            $open = $openFirst && $index === 0 ? ' open' : '';
            $index++;

            $html .= '<details class="nc-accordion__item"' . $open . '>'
                . '<summary>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</summary>'
                . '<div class="nc-accordion__panel">' . $ctx->richText($body) . '</div>'
                . '</details>';
        }

        // Icona a destra del summary: il CSS la disegna, il marcatore nativo e nascosto.
        $modifier = ($node->settings['icon'] ?? null) === 'plus' ? ' nc-accordion--plus' : '';
        $class = htmlspecialchars($ctx->classes($node) . $modifier, ENT_QUOTES, 'UTF-8');

        $schema = '';
        if (($node->settings['faq'] ?? false) === true && (bool) apply_filters('nucleo/faq_schema', true)) {
            $schema = self::faqSchema(is_array($items) ? $items : []);
        }

        return '<div class="' . $class . '">' . $html . '</div>' . $schema;
    }

    /**
     * JSON-LD FAQPage con domande (title) e risposte (html senza tag). Vuoto se non ci sono voci valide.
     * @param array<mixed> $items
     */
    private static function faqSchema(array $items): string
    {
        $entities = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $question = is_string($item['title'] ?? null) ? trim($item['title']) : '';
            $answer = is_string($item['html'] ?? null)
                ? trim(html_entity_decode(wp_strip_all_tags($item['html']), ENT_QUOTES, 'UTF-8'))
                : '';
            if ($question === '' || $answer === '') {
                continue;
            }

            $entities[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
            ];
        }

        if ($entities === []) {
            return '';
        }

        // Senza flag wp_json_encode escapa "/": "</script>" nel testo diventa "<\/script>".
        $json = wp_json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities]);

        return is_string($json) ? '<script type="application/ld+json">' . $json . '</script>' : '';
    }
}
