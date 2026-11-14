<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\DynamicRenderer;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;
use Nucleo\Compiler\LinkRefs;
use WP_Post;
use WP_Post_Type;
use WP_Term;

final class BreadcrumbsRenderer implements Renderer, DynamicRenderer
{
    private bool $hasCurrent = false;

    public function render(Node $node, RenderContext $ctx): string
    {
        $homeLabel = is_string($node->settings['homeLabel'] ?? null) ? $node->settings['homeLabel'] : 'Home';
        $parent = $node->settings['parent'] ?? null;

        return $ctx->slot('nc/breadcrumbs', [
            'class' => trim($ctx->classes($node)),
            'homeLabel' => $homeLabel,
            'archive' => ($node->settings['archive'] ?? false) === true,
            'parent' => is_array($parent) ? [
                'label' => is_string($parent['label'] ?? null) ? $parent['label'] : '',
                'href' => is_string($parent['href'] ?? null) ? $parent['href'] : '',
            ] : [],
            'taxonomy' => is_string($node->settings['taxonomy'] ?? null) ? $node->settings['taxonomy'] : '',
        ], '');
    }

    public function renderSlot(array $data): string
    {
        $class = is_string($data['class'] ?? null) ? $data['class'] : '';

        $fromPlugin = $this->fromPlugin();
        if ($fromPlugin !== '') {
            return '<div class="' . esc_attr($class) . '">' . $fromPlugin . '</div>';
        }

        $trail = $this->trail($data);

        return '<nav class="' . esc_attr($class) . '" aria-label="Breadcrumb"><ol>' . $this->listItems($trail) . '</ol></nav>'
            . $this->jsonLd($trail);
    }

    /**
     * BreadcrumbList dello stesso percorso del blocco. StructuredData la omette quando la pagina contiene il blocco.
     *
     * @param list<array{0:string,1:?string}> $trail
     */
    private function jsonLd(array $trail): string
    {
        $list = [];
        foreach ($trail as $index => [$label, $url]) {
            $item = ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $label];
            // Una voce senza link (parent non risolto) resta senza item; l'ultima è la pagina corrente.
            $link = $url ?? ($this->hasCurrent && $index === count($trail) - 1 ? self::currentUrl() : null);
            if ($link !== null) {
                $item['item'] = $link;
            }
            $list[] = $item;
        }
        if (count($list) < 2) {
            return '';
        }

        // Senza flag wp_json_encode escapa "/": "</script>" nel testo diventa "<\/script>".
        $json = wp_json_encode(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list]);

        return is_string($json) ? wp_get_inline_script_tag($json, ['type' => 'application/ld+json']) : '';
    }

    private static function currentUrl(): ?string
    {
        $object = get_queried_object();
        if ($object instanceof WP_Post) {
            $link = get_permalink($object);

            return is_string($link) ? $link : null;
        }
        if ($object instanceof WP_Term) {
            $link = get_term_link($object);

            return is_string($link) ? $link : null;
        }

        return null;
    }

    /** Output del plugin SEO attivo, se presente. Gia' HTML prodotto dal plugin. */
    private function fromPlugin(): string
    {
        if (function_exists('yoast_breadcrumb')) {
            $html = yoast_breadcrumb('', '', false);

            return is_string($html) ? $html : '';
        }
        if (function_exists('rank_math_get_breadcrumbs')) {
            $html = rank_math_get_breadcrumbs();

            return is_string($html) ? $html : '';
        }

        return '';
    }

    /**
     * Home, parent o archivio, termini, antenati e corrente. La voce corrente, se c'è, è l'ultima e ha url null.
     *
     * @param array<string,mixed> $data
     * @return list<array{0:string,1:?string}>
     */
    private function trail(array $data): array
    {
        $this->hasCurrent = false;
        $homeLabel = is_string($data['homeLabel'] ?? null) ? $data['homeLabel'] : 'Home';
        $items = [[$homeLabel, home_url('/')]];
        $current = '';

        $parent = $this->parentItem($data['parent'] ?? null);
        $object = get_queried_object();
        if ($parent !== null) {
            $items[] = $parent;
        } elseif ($object instanceof WP_Post && ($data['archive'] ?? false) === true) {
            $archive = $this->archiveItem($object->post_type);
            if ($archive !== null) {
                $items[] = $archive;
            }
        }

        if ($object instanceof WP_Post) {
            $taxonomy = is_string($data['taxonomy'] ?? null) ? $data['taxonomy'] : '';
            foreach ($this->termChain($object, $taxonomy) as $termItem) {
                $items[] = $termItem;
            }
            foreach (array_reverse(get_post_ancestors($object)) as $id) {
                $items[] = [get_the_title($id), (string) get_permalink($id)];
            }
            $current = get_the_title($object);
        } elseif ($object instanceof WP_Term) {
            foreach (array_reverse(get_ancestors($object->term_id, $object->taxonomy, 'taxonomy')) as $id) {
                $ancestor = get_term($id, $object->taxonomy);
                $link = $ancestor instanceof WP_Term ? get_term_link($ancestor) : null;
                if ($ancestor instanceof WP_Term && is_string($link)) {
                    $items[] = [$ancestor->name, $link];
                }
            }
            $current = $object->name;
        }

        $trail = [];
        foreach ($items as [$label, $url]) {
            $trail[] = [(string) $label, $url === null ? null : (string) $url];
        }
        if ($current !== '') {
            $trail[] = [$current, null];
            $this->hasCurrent = true;
        }

        return $trail;
    }

    /** @param list<array{0:string,1:?string}> $trail */
    private function listItems(array $trail): string
    {
        $out = '';
        $last = count($trail) - 1;
        foreach ($trail as $index => [$label, $url]) {
            $text = esc_html($label);
            if ($index === $last && $this->hasCurrent) {
                $out .= '<li aria-current="page">' . $text . '</li>';
                continue;
            }
            $out .= '<li>' . ($url === null ? $text : '<a href="' . esc_url($url) . '">' . $text . '</a>') . '</li>';
        }

        return $out;
    }

    /**
     * Voce parent. Href vuoto o non risolto (#nc-unresolved): voce senza link.
     *
     * @return array{0:string,1:?string}|null
     */
    private function parentItem(mixed $parent): ?array
    {
        if (!is_array($parent)) {
            return null;
        }
        $label = is_string($parent['label'] ?? null) ? trim($parent['label']) : '';
        if ($label === '') {
            return null;
        }
        $href = is_string($parent['href'] ?? null) ? trim($parent['href']) : '';

        return [$label, $href === '' || $href === LinkRefs::UNRESOLVED ? null : $href];
    }

    /**
     * Archivio del tipo di contenuto del post; null se il tipo non ha un link di archivio.
     * @return array{0:string,1:string}|null
     */
    private function archiveItem(string $postType): ?array
    {
        $typeObject = get_post_type_object($postType);
        $link = get_post_type_archive_link($postType);
        // Un archivio uguale alla home (articoli con la home sugli ultimi post) ripeterebbe la prima voce.
        if (
            !$typeObject instanceof WP_Post_Type || !is_string($link) || $link === ''
            || untrailingslashit($link) === untrailingslashit(home_url('/'))
        ) {
            return null;
        }

        return [$typeObject->labels->name, $link];
    }

    /**
     * Primo termine del post nella tassonomia, preceduto dai suoi antenati.
     *
     * @return list<array{0:string,1:string}>
     */
    private function termChain(WP_Post $post, string $taxonomy): array
    {
        if ($taxonomy === '' || !taxonomy_exists($taxonomy) || !is_taxonomy_viewable($taxonomy)) {
            return [];
        }
        // Primo termine nell'ordine di get_the_terms (per nome), non un termine "principale".
        $terms = get_the_terms($post->ID, $taxonomy);
        if (!is_array($terms) || !isset($terms[0]) || !$terms[0] instanceof WP_Term) {
            return [];
        }
        $term = $terms[0];

        $chain = [];
        foreach (array_reverse(get_ancestors($term->term_id, $taxonomy, 'taxonomy')) as $id) {
            $ancestor = get_term($id, $taxonomy);
            $link = $ancestor instanceof WP_Term ? get_term_link($ancestor) : null;
            if ($ancestor instanceof WP_Term && is_string($link)) {
                $chain[] = [$ancestor->name, $link];
            }
        }
        $link = get_term_link($term);
        if (is_string($link)) {
            $chain[] = [$term->name, $link];
        }

        return $chain;
    }
}
