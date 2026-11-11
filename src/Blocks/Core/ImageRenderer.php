<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\ColumnAware;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;
use Nucleo\Media\ImageFormats;

final class ImageRenderer implements Renderer
{
    /** Tipi ammessi per le sorgenti di <picture>; l'originale png/jpg resta il fallback dell'<img>. */
    private const SOURCE_TYPES = ['image/avif', 'image/webp', 'image/png', 'image/jpeg'];

    public function render(Node $node, RenderContext $ctx): string
    {
        $decorative = ($node->settings['decorative'] ?? false) === true;

        $attrs = [
            'class' => trim($ctx->classes($node)),
            'src' => $this->str($node->content['src'] ?? null),
            'alt' => $decorative ? '' : $this->str($node->content['alt'] ?? null),
        ];

        $width = $node->content['width'] ?? null;
        if (is_int($width) && $width > 0) {
            $attrs['width'] = (string) $width;
        }
        $height = $node->content['height'] ?? null;
        if (is_int($height) && $height > 0) {
            $attrs['height'] = (string) $height;
        }

        $srcset = $this->str($node->content['srcset'] ?? null);
        // Senza srcset nel documento: quello di WordPress dalle misure dell'allegato, come wp_get_attachment_image.
        $id = $this->attachmentId($this->str($node->content['id'] ?? null), $attrs['src']);
        if ($srcset === '' && $id > 0) {
            $auto = self::attachmentSrcset($id, $attrs['src']);
            if ($auto !== null) {
                $srcset = $auto['srcset'];
                // Le misure del file servito evitano lo spostamento del layout e danno la regola di sizes.
                if (!isset($attrs['width'], $attrs['height'])) {
                    $attrs['width'] = (string) $auto['width'];
                    $attrs['height'] = (string) $auto['height'];
                }
            }
        }
        if ($srcset !== '') {
            $attrs['srcset'] = $srcset;
        }
        $sizes = $this->str($node->content['sizes'] ?? null);
        // Dentro colonne: la frazione di schermo della colonna, così la prima immagine di una griglia non scarica l'originale.
        if ($sizes === '' && $srcset !== '' && $ctx instanceof ColumnAware) {
            $sizes = $ctx->columnSizes(isset($attrs['width']) ? (int) $attrs['width'] : null) ?? '';
        }
        // Senza sizes il browser sceglie dallo srcset come per 100vw: con la larghezza nota usa la regola di WordPress.
        if ($sizes === '' && isset($attrs['width'])) {
            $sizes = '(max-width: ' . $attrs['width'] . 'px) 100vw, ' . $attrs['width'] . 'px';
        }
        if ($sizes !== '' && $srcset !== '') {
            $attrs['sizes'] = $sizes;
        }

        $html = '<img';
        foreach ($attrs as $name => $value) {
            $html .= ' ' . $name . '="' . $this->esc($value) . '"';
        }
        $html .= ' decoding="async"';

        // Un'immagine decorativa non partecipa alla priorita LCP.
        $priority = !$decorative && $ctx->claimLcp($node->id);
        $html .= $priority ? ' fetchpriority="high"' : ' loading="lazy"';

        return Picture::wrap($html . '>', $this->sources($node, $id), $sizes);
    }

    /**
     * srcset di WordPress per l'allegato e misure del file in src (originale o formato intermedio).
     * Null senza metadati, senza formati intermedi o fuori da WordPress.
     * @return array{srcset:string,width:int,height:int}|null
     */
    public static function attachmentSrcset(int $id, string $src): ?array
    {
        if (!function_exists('wp_calculate_image_srcset')) {
            return null;
        }
        $meta = wp_get_attachment_metadata($id);
        if (!is_array($meta) || !is_int($meta['width'] ?? null) || !is_int($meta['height'] ?? null)) {
            return null;
        }
        $size = [$meta['width'], $meta['height']];
        $file = wp_basename((string) strtok($src, '?'));
        foreach (is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [] as $intermediate) {
            if (
                is_array($intermediate) && ($intermediate['file'] ?? null) === $file
                && is_int($intermediate['width'] ?? null) && is_int($intermediate['height'] ?? null)
            ) {
                $size = [$intermediate['width'], $intermediate['height']];
                break;
            }
        }
        $srcset = wp_calculate_image_srcset($size, $src, $meta, $id);

        return is_string($srcset) && $srcset !== '' ? ['srcset' => $srcset, 'width' => $size[0], 'height' => $size[1]] : null;
    }

    /**
     * Sorgenti di <picture>: quelle manuali vincono; altrimenti, con formati "auto", quelle generate per l'allegato.
     * Con formati "off" non c'e' <picture>.
     * @return list<array{type:string,srcset:string}>
     */
    private function sources(Node $node, int $id): array
    {
        if (($node->settings['formats'] ?? 'auto') === 'off') {
            return [];
        }

        $sources = $this->manualSources($node->content['sources'] ?? null);
        if ($sources !== []) {
            return $sources;
        }

        return $id > 0 ? ImageFormats::sources($id) : [];
    }

    /** @return list<array{type:string,srcset:string}> */
    private function manualSources(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $sources = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = $this->str($item['type'] ?? null);
            $srcset = $this->str($item['srcset'] ?? null);
            if (in_array($type, self::SOURCE_TYPES, true) && $srcset !== '') {
                $sources[] = ['type' => $type, 'srcset' => $srcset];
            }
        }

        return $sources;
    }

    /** Id dell'allegato: content.id se numerico, altrimenti dall'URL di src; 0 se non si trova. */
    private function attachmentId(string $id, string $src): int
    {
        if (ctype_digit($id) && (int) $id > 0) {
            return (int) $id;
        }
        if ($src !== '' && function_exists('attachment_url_to_postid')) {
            return (int) attachment_url_to_postid($src);
        }

        return 0;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
