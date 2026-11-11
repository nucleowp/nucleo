<?php

declare(strict_types=1);

namespace Nucleo\Media;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Formati moderni (AVIF, WebP) generati accanto all'immagine caricata e alle sue misure intermedie.
 * Facoltativi: si accendono con l'opzione del sito imageFormats; l'originale resta il fallback di <picture>.
 */
final class ImageFormats
{
    /** Opzione del sito: formati da generare, in ordine di preferenza. Vuota = spento. */
    public const OPTION = 'nucleo_image_formats';

    /** Formati ammessi, in ordine di preferenza per <picture>. */
    public const FORMATS = ['avif' => 'image/avif', 'webp' => 'image/webp'];

    /** Meta dell'allegato: formato → misura ("full" o nome della misura) → nome del file nella stessa cartella. */
    public const META = '_nucleo_formats';

    /** Filtro wp_generate_attachment_metadata e azione delete_attachment. */
    public static function register(): void
    {
        add_filter('wp_generate_attachment_metadata', static function (array $metadata, int $attachmentId): array {
            if (self::enabled() !== [] && wp_attachment_is_image($attachmentId)) {
                // Le misure intermedie si leggono dai metadati salvati: li salvo prima di generare.
                wp_update_attachment_metadata($attachmentId, $metadata);
                self::generate($attachmentId);
            }

            return $metadata;
        }, 20, 2);

        add_action('delete_attachment', static function (int $attachmentId): void {
            $meta = get_post_meta($attachmentId, self::META, true);
            $file = get_attached_file($attachmentId);
            if (!is_array($meta) || !is_string($file) || $file === '') {
                return;
            }

            $dir = dirname($file);
            foreach ($meta as $names) {
                if (!is_array($names)) {
                    continue;
                }
                foreach ($names as $name) {
                    if (is_string($name) && $name !== '') {
                        wp_delete_file($dir . '/' . wp_basename($name));
                    }
                }
            }
        });
    }

    /** @return list<string> formati accesi sul sito, nell'ordine di FORMATS */
    public static function enabled(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return [];
        }

        return array_values(array_filter(
            array_keys(self::FORMATS),
            static fn(string $format): bool => in_array($format, $stored, true)
        ));
    }

    /**
     * Salva i formati accesi; lista vuota spegne.
     * @param array<mixed> $formats
     */
    public static function save(array $formats): void
    {
        if ($formats === []) {
            delete_option(self::OPTION);

            return;
        }

        update_option(self::OPTION, array_values($formats), false);
    }

    /** null se $formats è una lista di chiavi di FORMATS, altrimenti WP_Error invalid_input. */
    public static function check(mixed $formats): ?WP_Error
    {
        if (
            !is_array($formats)
            || !array_is_list($formats)
            || count(array_unique($formats)) !== count($formats)
            || array_diff($formats, array_keys(self::FORMATS)) !== []
        ) {
            return new WP_Error('invalid_input', __('Invalid formats: use a list without duplicates of avif, webp.', 'nucleo'));
        }

        return null;
    }

    /** @return list<string> formati che l'editor di immagini del server sa scrivere */
    public static function supported(): array
    {
        return array_values(array_filter(
            array_keys(self::FORMATS),
            static fn(string $format): bool => wp_image_editor_supports(['mime_type' => self::FORMATS[$format]])
        ));
    }

    /**
     * Genera i formati accesi e supportati per l'allegato: originale e misure intermedie. Salta il formato dell'originale.
     * @return array<string,array<string,string>> il valore di META salvato
     */
    public static function generate(int $attachmentId): array
    {
        $meta = [];
        $file = get_attached_file($attachmentId);
        if (!is_string($file) || $file === '' || !is_file($file)) {
            update_post_meta($attachmentId, self::META, $meta);

            return $meta;
        }

        $dir = dirname($file);
        $formats = array_values(array_intersect(self::enabled(), self::supported()));

        // Sorgenti: l'originale e ogni misura intermedia, con il mime noto.
        $sources = ['full' => [$file, (string) get_post_mime_type($attachmentId)]];
        $wpMeta = wp_get_attachment_metadata($attachmentId);
        $sizes = is_array($wpMeta) && is_array($wpMeta['sizes'] ?? null) ? $wpMeta['sizes'] : [];
        foreach ($sizes as $name => $size) {
            if (is_array($size) && is_string($size['file'] ?? null) && $size['file'] !== '') {
                $sources[(string) $name] = [$dir . '/' . wp_basename($size['file']), (string) ($size['mime-type'] ?? '')];
            }
        }

        foreach ($formats as $format) {
            $mime = self::FORMATS[$format];
            foreach ($sources as $label => [$source, $sourceMime]) {
                if ($sourceMime === $mime || !is_file($source)) {
                    continue;
                }

                $name = wp_basename($source) . '.' . $format;
                try {
                    $editor = wp_get_image_editor($source);
                    if ($editor instanceof WP_Error) {
                        continue;
                    }
                    $saved = $editor->save($dir . '/' . $name, $mime);
                } catch (\Throwable) {
                    continue;
                }
                if (!is_array($saved)) {
                    continue;
                }

                $meta[$format][(string) $label] = wp_basename((string) $saved['file']);
            }
        }

        update_post_meta($attachmentId, self::META, $meta);

        return $meta;
    }

    /**
     * Sorgenti per <picture>, nel formato preferito per primo: type e srcset con le stesse larghezze dell'originale.
     * Lista vuota se l'allegato non ha formati generati.
     * @return list<array{type:string,srcset:string}>
     */
    public static function sources(int $attachmentId): array
    {
        $meta = get_post_meta($attachmentId, self::META, true);
        $url = wp_get_attachment_url($attachmentId);
        if (!is_array($meta) || $meta === [] || !is_string($url) || $url === '') {
            return [];
        }

        $wpMeta = wp_get_attachment_metadata($attachmentId);
        $widths = [];
        if (is_array($wpMeta)) {
            $fullWidth = $wpMeta['width'] ?? null;
            $fullHeight = $wpMeta['height'] ?? null;
            $widths['full'] = $fullWidth;
            foreach (is_array($wpMeta['sizes'] ?? null) ? $wpMeta['sizes'] : [] as $name => $size) {
                // Come lo srcset di WordPress: solo le misure con le proporzioni dell'originale, niente ritagli.
                $sameRatio = is_array($size) && is_int($fullWidth) && is_int($fullHeight)
                    && is_int($size['width'] ?? null) && is_int($size['height'] ?? null)
                    && wp_image_matches_ratio($fullWidth, $fullHeight, $size['width'], $size['height']);
                $widths[(string) $name] = $sameRatio ? $size['width'] : null;
            }
        }

        $baseUrl = dirname($url);
        $sources = [];
        foreach (array_keys(self::FORMATS) as $format) {
            if (!is_array($meta[$format] ?? null)) {
                continue;
            }

            $candidates = [];
            foreach ($meta[$format] as $label => $name) {
                $width = $widths[(string) $label] ?? null;
                if (!is_string($name) || !is_int($width) || $width <= 0) {
                    continue;
                }
                $candidates[] = $baseUrl . '/' . wp_basename($name) . ' ' . $width . 'w';
            }

            if ($candidates !== []) {
                $sources[] = ['type' => self::FORMATS[$format], 'srcset' => implode(', ', $candidates)];
            }
        }

        return $sources;
    }
}
