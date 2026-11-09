<?php

declare(strict_types=1);

namespace Nucleo\Seo;

use Nucleo\Integrations\SeoBridge;

/**
 * Title e meta SEO del documento Nucleo sulle pagine singole. Lo chiama Frontend::boot solo se il runtime
 * ha meta.seo. Spento se Yoast o Rank Math sono attivi, perché stampano già i propri tag.
 * Il canonical lo lascia a WordPress.
 */
final class HeadMeta
{
    /** @var array<string,mixed> meta.seo del documento della richiesta corrente */
    private static array $seo = [];

    /** @param array<string,mixed> $seo meta.seo del documento, già non vuoto */
    public static function register(array $seo): void
    {
        if (SeoBridge::active() !== null) {
            return;
        }

        self::$seo = $seo;
        add_filter('pre_get_document_title', [self::class, 'documentTitle']);
        add_action('wp_head', [self::class, 'output'], 1);
    }

    /** Title del documento se ha meta.seo.title, altrimenti il valore ricevuto dal filtro. */
    public static function documentTitle(mixed $title): mixed
    {
        $seoTitle = self::text(self::$seo['title'] ?? null);

        return $seoTitle !== '' ? $seoTitle : $title;
    }

    /** Tag SEO nel head della pagina singola. */
    public static function output(): void
    {
        $seo = self::$seo;
        $id = get_queried_object_id();
        $title = self::text($seo['title'] ?? null);
        if ($title === '') {
            $title = get_the_title($id);
        }
        $description = self::text($seo['description'] ?? null);
        $image = esc_url(self::text($seo['image'] ?? null));

        $tags = [];
        if ($description !== '') {
            $tags[] = '<meta name="description" content="' . esc_attr($description) . '">';
        }
        $tags[] = '<meta property="og:title" content="' . esc_attr($title) . '">';
        if ($description !== '') {
            $tags[] = '<meta property="og:description" content="' . esc_attr($description) . '">';
        }
        $tags[] = '<meta property="og:url" content="' . esc_url((string) get_permalink($id)) . '">';
        $tags[] = '<meta property="og:type" content="website">';
        if ($image !== '') {
            $tags[] = '<meta property="og:image" content="' . $image . '">';
        }
        if (($seo['noindex'] ?? false) === true) {
            $tags[] = '<meta name="robots" content="noindex">';
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ogni valore è escapato quando entra in $tags.
        echo implode("\n", $tags) . "\n";
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
