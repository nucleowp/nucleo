<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;
use Nucleo\Integrations\Consent;

/** Facade: miniatura statica, l'iframe del provider viene caricato da view.js al click. */
final class VideoRenderer implements Renderer
{
    private const PROVIDERS = ['youtube', 'vimeo'];
    private const YOUTUBE_ID = '/^[A-Za-z0-9_-]{6,20}$/D';
    private const VIMEO_ID = '/^[0-9]+$/D';
    private const POSTER = '#^(https://|/(?!/))[^\s"\'<>]+$#D';

    public function render(Node $node, RenderContext $ctx): string
    {
        $provider = $node->content['provider'] ?? null;
        $provider = is_string($provider) && in_array($provider, self::PROVIDERS, true) ? $provider : '';

        $id = $this->videoId($provider, $node->content['videoId'] ?? null);

        $title = $node->content['title'] ?? '';
        $title = is_string($title) && trim($title) !== '' ? trim($title) : 'video';
        $label = sprintf(/* translators: %s: video title */ __('Play: %s', 'nucleo'), $title);

        $consent = $this->consentAttributes($node->settings['consent'] ?? 'marketing');
        $own = $this->poster($node->content['poster'] ?? null);
        $poster = $own ?? $this->thumbnail($provider, $id);
        // Con un plugin di consenso la miniatura del provider (server di Google o Vimeo) aspetta il consenso:
        // view.js copia data-nc-src in src. Un poster proprio si carica subito.
        $deferred = $consent !== [] && $own === null;
        $img = $poster === null
            ? ''
            : '<img ' . ($deferred ? 'data-nc-src' : 'src') . '="' . self::esc($poster) . '" alt="" loading="lazy" width="480" height="360">';
        $notice = $consent === []
            ? ''
            : '<span class="nc-video__notice">' . self::esc(__('The video loads after cookie consent.', 'nucleo')) . '</span>';

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
            . '" data-provider="' . self::esc($provider)
            . '" data-id="' . self::esc($id) . '"' . $extra . '>'
            . '<button type="button" class="nc-video__play" aria-label="' . self::esc($label) . '">'
            . $img
            . '</button>' . $notice . '</div>';
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

    private function videoId(string $provider, mixed $id): string
    {
        if (!is_string($id)) {
            return '';
        }

        $pattern = match ($provider) {
            'youtube' => self::YOUTUBE_ID,
            'vimeo' => self::VIMEO_ID,
            default => null,
        };

        return $pattern !== null && preg_match($pattern, $id) === 1 ? $id : '';
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
        return $provider === 'youtube' && $id !== ''
            ? 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg'
            : null;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
