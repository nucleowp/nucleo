<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

/**
 * Svuota le cache di pagina dei plugin di caching presenti. Ogni plugin è chiamato solo se esiste.
 */
final class CachePurger
{
    public static function purgePost(int $id): void
    {
        $permalink = get_permalink($id);
        $url = is_string($permalink) && $permalink !== '' ? $permalink : null;

        if ($url !== null) {
            do_action('litespeed_purge_url', $url);
        }
        // Tag inviato nell'header X-LiteSpeed-Tag del documento (Frontend): svuota solo le pagine di questo post.
        if (has_action('litespeed_purge')) {
            do_action('litespeed_purge', 'nucleo-post-' . $id);
        }
        if (function_exists('rocket_clean_post')) {
            rocket_clean_post($id);
        }
        if (function_exists('w3tc_flush_post')) {
            w3tc_flush_post($id);
        }
        if (function_exists('wpsc_delete_post_cache')) {
            wpsc_delete_post_cache($id);
        }
        if ($url !== null && function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache($url);
        }
        if ($url !== null && function_exists('sg_cachepress_purge_url')) {
            sg_cachepress_purge_url($url);
        }
        if (function_exists('wpfc_clear_post_cache_by_id')) {
            wpfc_clear_post_cache_by_id($id);
        }
        if (function_exists('breeze_clear_all_cache')) {
            breeze_clear_all_cache();
        }

        if (class_exists('CF\WordPress\Hooks')) {
            (new \CF\WordPress\Hooks())->purgeCacheByRelevantURLs($id);
        }
        if (class_exists('WpeCommon')) {
            \WpeCommon::purge_varnish_cache($id);
        }
        global $kinsta_cache;
        if (
            isset($kinsta_cache->kinsta_cache_purge)
            && is_object($kinsta_cache->kinsta_cache_purge)
            && method_exists($kinsta_cache->kinsta_cache_purge, 'initiate_purge')
        ) {
            $kinsta_cache->kinsta_cache_purge->initiate_purge($id, 'post');
        }

        if ($url !== null) {
            do_action('nucleo/purge_url', $url);
        }
    }

    public static function purgeAll(): void
    {
        do_action('litespeed_purge_all');
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
        }

        if (class_exists('CF\WordPress\Hooks')) {
            (new \CF\WordPress\Hooks())->purgeCacheEverything();
        }
        if (class_exists('WpeCommon')) {
            \WpeCommon::purge_varnish_cache();
            \WpeCommon::purge_memcached();
        }
        global $kinsta_cache;
        if (
            isset($kinsta_cache->kinsta_cache_purge)
            && is_object($kinsta_cache->kinsta_cache_purge)
            && method_exists($kinsta_cache->kinsta_cache_purge, 'purge_complete_caches')
        ) {
            $kinsta_cache->kinsta_cache_purge->purge_complete_caches();
        }

        do_action('nucleo/purge_all');
    }
}
