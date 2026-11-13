<?php

declare(strict_types=1);

namespace Nucleo\OAuth;

defined('ABSPATH') || exit;

use Nucleo\Support\HostResolver;
use Nucleo\Support\PinnedHttp;

/**
 * Risolve il client_id: se è un URL https scarica il documento di metadati (Client ID Metadata Documents),
 * altrimenti cerca il client registrato nel database. Il download è protetto da SSRF e da DNS rebinding:
 * l'host viene risolto e verificato, poi fissato all'IP verificato per la sola richiesta.
 */
final class ClientFetcher
{
    private const CACHE_PREFIX = 'nucleo_cimd_';
    private const CACHE_TTL = HOUR_IN_SECONDS;
    private const TIMEOUT = 5;
    private const MAX_BYTES = 65536;

    private Server $server;
    private UrlGuard $guard;

    public function __construct(?Server $server = null, ?UrlGuard $guard = null)
    {
        $this->server = $server ?? new Server();
        $this->guard = $guard ?? new UrlGuard([HostResolver::class, 'resolve']);
    }

    /** @return array{client_id:string,client_name:string,redirect_uris:list<string>,kind:string}|null */
    public function resolve(string $clientId): ?array
    {
        if (strtolower((string) wp_parse_url($clientId, PHP_URL_SCHEME)) !== 'https') {
            return $this->server->findClient($clientId);
        }

        // Con una lista di domini ammessi, il client_id deve stare su uno di questi (o un sottodominio) prima di ogni download.
        $host = strtolower((string) wp_parse_url($clientId, PHP_URL_HOST));
        if (!Server::hostAllowed($host, Server::allowedDomains())) {
            return null;
        }

        $key = self::CACHE_PREFIX . md5($clientId);
        $cached = self::toClient(get_transient($key));
        if ($cached !== null) {
            return $cached;
        }

        $doc = $this->fetch($clientId);
        if ($doc !== null) {
            $this->server->rememberMetadataClient($doc);
            set_transient($key, $doc, self::CACHE_TTL);
        }

        return $doc;
    }

    /** @return array{client_id:string,client_name:string,redirect_uris:list<string>,kind:string}|null */
    private function fetch(string $url): ?array
    {
        $response = PinnedHttp::request('GET', $url, [
            'timeout' => self::TIMEOUT,
            'limit_response_size' => self::MAX_BYTES,
            'headers' => ['Accept' => 'application/json'],
        ], $this->guard);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > self::MAX_BYTES) {
            return null;
        }

        $doc = json_decode($body, true);
        if (!is_array($doc) || ClientMetadata::validate($doc, $url) !== []) {
            return null;
        }

        $normalized = ClientMetadata::normalize($doc);
        $normalized['kind'] = 'cimd';

        return $normalized;
    }

    /**
     * Ricostruisce il client dal transient controllandone la forma: un valore malformato viene scartato.
     *
     * @return array{client_id:string,client_name:string,redirect_uris:list<string>,kind:string}|null
     */
    private static function toClient(mixed $value): ?array
    {
        if (
            !is_array($value)
            || !is_string($value['client_id'] ?? null)
            || !is_string($value['client_name'] ?? null)
            || ($value['kind'] ?? null) !== 'cimd'
            || !is_array($value['redirect_uris'] ?? null)
            || !array_is_list($value['redirect_uris'])
        ) {
            return null;
        }
        $uris = [];
        foreach ($value['redirect_uris'] as $uri) {
            if (!is_string($uri)) {
                return null;
            }
            $uris[] = $uri;
        }

        return [
            'client_id' => $value['client_id'],
            'client_name' => $value['client_name'],
            'redirect_uris' => $uris,
            'kind' => 'cimd',
        ];
    }
}
