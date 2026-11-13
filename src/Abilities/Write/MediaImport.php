<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Media\ImageSaver;
use Nucleo\OAuth\UrlGuard;
use Nucleo\Support\HostResolver;
use Nucleo\Support\PinnedHttp;

/** nucleo/media-import: importa un'immagine pubblica da URL nella libreria media, con testo alternativo. */
final class MediaImport implements AbilityDefinition
{
    private const TIMEOUT = 15;
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const MAX_REDIRECTS = 3;

    public function name(): string
    {
        return 'nucleo/media-import';
    }

    public function args(): array
    {
        return [
            'label' => __('Import image', 'nucleo'),
            'description' => 'Downloads a public image from an https URL and adds it to the media library, with alt text. Accepts only jpeg, png, webp, gif and avif, up to 10 MB.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['url', 'alt'],
                'properties' => [
                    'url' => ['type' => 'string', 'minLength' => 1, 'description' => 'Public https URL of the image.'],
                    'alt' => ['type' => 'string', 'description' => 'Alternative text. Empty string for decorative images.'],
                    'title' => ['type' => 'string', 'description' => 'Media title.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'url' => ['type' => 'string'],
                    'width' => ['type' => 'integer', 'minimum' => 1],
                    'height' => ['type' => 'integer', 'minimum' => 1],
                    'error' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
        ];
    }

    public function execute(array $input): array|\WP_Error
    {
        $url = $input['url'] ?? null;
        $alt = $input['alt'] ?? null;
        $title = $input['title'] ?? null;
        if (!is_string($url) || !is_string($alt) || ($title !== null && !is_string($title))) {
            return new \WP_Error('invalid_input', __('Fields url, alt or title are not valid.', 'nucleo'));
        }

        $guard = new UrlGuard([HostResolver::class, 'resolve']);
        if (!$guard->isSafePublicUrl($url)) {
            return self::failure('invalid_url', __('URL not allowed: it must be https and point to a public host.', 'nucleo'));
        }

        // IP fissato a ogni passo; i redirect si seguono solo verso URL a loro volta verificati.
        $response = PinnedHttp::request('GET', $url, [
            'timeout' => self::TIMEOUT,
            'limit_response_size' => self::MAX_BYTES,
        ], $guard, self::MAX_REDIRECTS);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return self::failure('download_failed', __('Image download failed.', 'nucleo'));
        }

        $body = wp_remote_retrieve_body($response);

        return ImageSaver::save($body, pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME), $alt, $title);
    }

    public function permitted(array $input): bool
    {
        return current_user_can('upload_files');
    }

    /** @return array{ok:false,error:array{code:string,message:string}} */
    private static function failure(string $code, string $message): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    }
}
