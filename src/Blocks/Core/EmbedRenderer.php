<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\NodeValidator;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;
use Nucleo\Integrations\Consent;
use Nucleo\Schema\ValidationError;

/** Facade: pulsante con il titolo e miniatura opzionale, l'iframe viene caricato da view.js al click. */
final class EmbedRenderer implements Renderer, NodeValidator
{
    private const RATIOS = ['16:9', '4:3', '1:1', '21:9'];
    private const HTTPS = '#^https://#i';
    private const YOUTUBE_ID = '/^[A-Za-z0-9_-]{6,20}$/D';
    private const POSTER = '#^(https://|/(?!/))[^\s"\'<>]+$#D';

    public function render(Node $node, RenderContext $ctx): string
    {
        $url = $node->settings['url'] ?? null;
        $target = is_string($url) ? $this->target($url) : null;
        if ($target === null) {
            return '';
        }

        $title = $node->content['title'] ?? '';
        $title = is_string($title) && trim($title) !== '' ? trim($title) : 'embed';
        $label = sprintf(/* translators: %s: embed title */ __('Load: %s', 'nucleo'), $title);

        $ratio = $node->settings['aspectRatio'] ?? null;
        $ratio = is_string($ratio) && in_array($ratio, self::RATIOS, true) ? $ratio : '16:9';

        $consent = $this->consentAttributes($node->settings['consent'] ?? 'marketing');
        $own = $this->poster($node->content['poster'] ?? null);
        $poster = $own ?? $this->thumbnail($target['provider'], $target['id']);
        // Come nc/video: con un plugin di consenso la miniatura del provider aspetta il consenso (data-nc-src).
        $deferred = $consent !== [] && $own === null;
        $img = $poster === null
            ? ''
            : '<img ' . ($deferred ? 'data-nc-src' : 'src') . '="' . self::esc($poster) . '" alt="" loading="lazy" width="480" height="270">';
        $notice = $consent === []
            ? ''
            : '<span class="nc-embed__notice">' . self::esc(__('The content loads after cookie consent.', 'nucleo')) . '</span>';

        $classes = trim($ctx->classes($node));
        if (isset($consent['class'])) {
            $classes = trim($classes . ' ' . $consent['class']);
            unset($consent['class']);
        }
        $extra = '';
        foreach ($consent as $name => $value) {
            $extra .= ' ' . $name . '="' . self::esc($value) . '"';
        }

        return '<div class="' . self::esc($classes)
            . '" data-provider="' . self::esc($target['provider'])
            . '" data-src="' . self::esc($target['src'])
            . '" data-title="' . self::esc($title)
            . '" data-ratio="' . self::esc($ratio) . '"' . $extra . '>'
            . '<button type="button" class="nc-embed__play" aria-label="' . self::esc($label) . '">'
            . $img
            . '</button>' . $notice . '</div>';
    }

    public function validateNode(array $node, string $path): array
    {
        $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
        $url = $settings['url'] ?? null;
        if ($url === null) {
            return []; // il campo obbligatorio lo segnala lo schema
        }

        $at = "$path/settings/url";
        if (!is_string($url) || preg_match(self::HTTPS, $url) !== 1) {
            return [new ValidationError(
                $at,
                __('embed url must use https', 'nucleo'),
                $url,
                [],
                __('use an https address', 'nucleo'),
                'unsafe_url',
            )];
        }
        if ($this->target($url) === null) {
            return [new ValidationError(
                $at,
                __('youtube or vimeo address without a valid video id, or a host that is not an oEmbed provider known to WordPress', 'nucleo'),
                $url,
                [],
                __('use the address of a single video, or of a provider such as Spotify or SoundCloud; the nucleo/embed_hosts filter adds other hosts', 'nucleo'),
                'invalid_url',
            )];
        }

        return [];
    }

    /**
     * Provider riconosciuto dall'host e sorgente dell'iframe.
     *
     * @return array{provider: string, id: string, src: string}|null null se l'url non è https, non è valido o viene da un host non ammesso
     */
    private function target(string $url): ?array
    {
        if (preg_match(self::HTTPS, $url) !== 1) {
            return null;
        }
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if ($host === '') {
            return null;
        }
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);

        if (in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'youtu.be'], true)) {
            $id = $this->youtubeId($host, $path, $query);
            if (preg_match(self::YOUTUBE_ID, $id) !== 1) {
                return null;
            }

            return [
                'provider' => 'youtube',
                'id' => $id,
                'src' => 'https://www.youtube-nocookie.com/embed/' . $id . '?autoplay=1',
            ];
        }

        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true)) {
            if (preg_match('#^/(?:video/)?([0-9]+)(?:/|$)#', $path, $m) !== 1) {
                return null;
            }

            return [
                'provider' => 'vimeo',
                'id' => $m[1],
                'src' => 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1',
            ];
        }

        if ($host === 'maps.google.com' || ($host === 'google.com' && str_starts_with($path, '/maps'))) {
            return ['provider' => 'maps', 'id' => '', 'src' => $url];
        }

        // Embed generico solo da un provider oEmbed noto a WordPress, come il blocco Embed di core: nessun iframe da
        // un dominio qualsiasi. Il filtro nucleo/embed_hosts aggiunge altri host.
        $hosts = apply_filters('nucleo/embed_hosts', []);
        $allowed = is_array($hosts) && in_array($host, $hosts, true);
        if (!$allowed && _wp_oembed_get_object()->get_provider($url, ['discover' => false]) === false) {
            return null;
        }

        return ['provider' => 'generic', 'id' => '', 'src' => $url];
    }

    /** Id YouTube da youtu.be, watch?v=, /embed/ o /shorts/; stringa vuota se non c'è. */
    private function youtubeId(string $host, string $path, string $query): string
    {
        if ($host === 'youtu.be') {
            return trim($path, '/');
        }
        if (preg_match('#^/(?:embed|shorts)/([^/]+)#', $path, $m) === 1) {
            return $m[1];
        }
        parse_str($query, $params);
        $v = $params['v'] ?? null;

        return is_string($v) ? $v : '';
    }

    /** @return array<string, string> */
    private function consentAttributes(mixed $category): array
    {
        $category = is_string($category) && in_array($category, ['marketing', 'statistics', 'preferences', 'none'], true)
            ? $category
            : 'marketing';
        if ($category === 'none') {
            return [];
        }

        $plugin = Consent::plugin();
        if ($plugin === null) {
            return [];
        }

        return ['data-nc-consent' => $category] + Consent::attributes($category);
    }

    private function poster(mixed $poster): ?string
    {
        if (!is_string($poster) || preg_match(self::POSTER, $poster) !== 1) {
            return null;
        }

        return $poster;
    }

    private function thumbnail(string $provider, string $id): ?string
    {
        return $provider === 'youtube'
            ? 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg'
            : null;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
