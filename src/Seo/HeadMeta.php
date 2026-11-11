<?php

declare(strict_types=1);

namespace Nucleo\Seo;

defined('ABSPATH') || exit;

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
        $image = self::text($seo['image'] ?? null);

        // Ogni valore si escapa nel momento in cui viene stampato.
        if ($description !== '') {
            printf('<meta name="description" content="%s">' . "\n", esc_attr($description));
        }
        printf('<meta property="og:title" content="%s">' . "\n", esc_attr($title));
        if ($description !== '') {
            printf('<meta property="og:description" content="%s">' . "\n", esc_attr($description));
        }
        printf('<meta property="og:url" content="%s">' . "\n", esc_url((string) get_permalink($id)));
        echo '<meta property="og:type" content="website">' . "\n";
        if (esc_url($image) !== '') {
            printf('<meta property="og:image" content="%s">' . "\n", esc_url($image));
        }
        if (($seo['noindex'] ?? false) === true) {
            echo '<meta name="robots" content="noindex">' . "\n";
        }
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
