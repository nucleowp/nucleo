<?php

declare(strict_types=1);

namespace Nucleo\Runtime\Collection;

use Nucleo\Abilities\RateLimiter;
use Nucleo\Support\ClientIp;

/** GET nucleo/v1/collection/<doc>/<node>: frammento HTML dei risultati e conteggi. */
final class CollectionRest
{
    private const NAMESPACE = 'nucleo/v1';
    private const ROUTE = '/collection/(?P<doc>\d+)/(?P<node>[a-z0-9]+)';
    private const CACHE_CONTROL = 'public, max-age=60';

    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'GET',
            'callback' => [self::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!RateLimiter::hit('coll|' . ClientIp::get(), 60, 60)) {
            return new \WP_Error('rate_limited', 'Too many requests', ['status' => 429]);
        }

        $doc = (int) $request->get_param('doc');
        $node = (string) $request->get_param('node');

        $post = $doc > 0 ? get_post($doc) : null;
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish' || $post->post_password !== '') {
            return self::notFound();
        }

        $data = self::slotData($post->ID, $node);
        if ($data === null) {
            return self::notFound();
        }

        $raw = $data['config'] ?? null;
        $config = is_array($raw) ? CollectionConfig::fromArray($raw) : null;
        if ($config === null) {
            return self::notFound();
        }

        $params = wp_unslash($request->get_query_params());
        $state = CollectionState::fromRequest($config, $params);
        $template = is_array($data['template'] ?? null) ? $data['template'] : [];
        $page = CollectionCache::page($doc, $config, $state, $template);
        $append = ($params['append'] ?? null) === '1';

        $response = new \WP_REST_Response([
            'ok' => true,
            'html' => CollectionView::results($data, $config, $state, $page, $append),
            'count' => $page->total,
            'page' => $page->page,
            'pages' => $page->pages,
            'counts' => $page->counts,
            'status' => CollectionView::countText($data, $page->total),
        ]);
        $response->header('Cache-Control', self::CACHE_CONTROL);

        return $response;
    }

    /**
     * Dati dello slot nc/collection con il nodo indicato nel meta _nucleo_rt del documento; null se assente.
     * @return array<string,mixed>|null
     */
    private static function slotData(int $doc, string $node): ?array
    {
        $rt = get_post_meta($doc, '_nucleo_rt', true);
        if (!is_array($rt) || !is_array($rt['slots'] ?? null)) {
            return null;
        }
        foreach ($rt['slots'] as $slot) {
            if (!is_array($slot) || ($slot['block'] ?? null) !== 'nc/collection') {
                continue;
            }
            $data = $slot['data'] ?? null;
            if (is_array($data) && ($data['node'] ?? null) === $node) {
                return $data;
            }
        }

        return null;
    }

    private static function notFound(): \WP_Error
    {
        return new \WP_Error('nucleo_collection_not_found', 'Not found', ['status' => 404]);
    }
}
