<?php

declare(strict_types=1);

namespace Nucleo\Integrations;

use Nucleo\Services;

/** Legge title, meta description, noindex e canonical dal plugin SEO attivo (Yoast, Rank Math), altrimenti dal post. */
final class SeoBridge
{
    /** @return array{title:string,description:string,source:string,noindex:bool,canonical:string} */
    public static function meta(int $postId): array
    {
        $plugin = self::active();

        if ($plugin === 'yoast') {
            $meta = self::yoast($postId);
        } elseif ($plugin === 'rankmath') {
            $robots = get_post_meta($postId, 'rank_math_robots', true);
            $meta = [
                'title' => self::string(get_post_meta($postId, 'rank_math_title', true)),
                'description' => self::string(get_post_meta($postId, 'rank_math_description', true)),
                'source' => 'rankmath',
                'noindex' => is_array($robots) && in_array('noindex', $robots, true),
                'canonical' => self::string(get_post_meta($postId, 'rank_math_canonical_url', true)),
            ];
        } else {
            $seo = self::documentSeo($postId) ?? [];
            $meta = [
                'title' => self::string($seo['title'] ?? null) ?: get_the_title($postId),
                'description' => self::string($seo['description'] ?? null),
                'source' => 'wordpress',
                'noindex' => get_option('blog_public') === '0',
                'canonical' => '',
            ];
        }

        return apply_filters('nucleo/seo/meta', $meta, $postId);
    }

    /**
     * meta.seo del documento Nucleo del post, null se il post non ha un documento Nucleo.
     * @return array<string,mixed>|null
     */
    public static function documentSeo(int $postId): ?array
    {
        $document = Services::repository()->find($postId)['document'] ?? null;
        if (!is_array($document)) {
            return null;
        }

        $seo = $document['meta']['seo'] ?? null;

        return is_array($seo) ? $seo : [];
    }

    /** Nome del plugin SEO attivo ('yoast' o 'rankmath'), altrimenti null. */
    public static function active(): ?string
    {
        if (defined('WPSEO_VERSION')) {
            return 'yoast';
        }

        if (defined('RANK_MATH_VERSION')) {
            return 'rankmath';
        }

        return null;
    }

    /** @return array{title:string,description:string,source:string,noindex:bool,canonical:string} */
    private static function yoast(int $postId): array
    {
        $noindex = get_post_meta($postId, '_yoast_wpseo_meta-robots-noindex', true) === '1';
        $canonical = self::string(get_post_meta($postId, '_yoast_wpseo_canonical', true));

        if (function_exists('YoastSEO') && isset(YoastSEO()->meta)) {
            $presentation = YoastSEO()->meta->for_post($postId);
            $title = is_object($presentation) ? ($presentation->title ?? null) : null;
            $description = is_object($presentation) ? ($presentation->description ?? null) : null;
            if (is_string($title) && is_string($description)) {
                return ['title' => $title, 'description' => $description, 'source' => 'yoast', 'noindex' => $noindex, 'canonical' => $canonical];
            }
        }

        return [
            'title' => self::string(get_post_meta($postId, '_yoast_wpseo_title', true)),
            'description' => self::string(get_post_meta($postId, '_yoast_wpseo_metadesc', true)),
            'source' => 'yoast',
            'noindex' => $noindex,
            'canonical' => $canonical,
        ];
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
