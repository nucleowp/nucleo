<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Media\ImageFormats;

/** nucleo/media-formats: genera i formati moderni (AVIF, WebP) per le immagini già caricate. */
final class MediaFormats implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/media-formats';
    }

    public function args(): array
    {
        return [
            'label' => __('Generate image formats', 'nucleo'),
            'description' => 'Generates the modern image formats (avif, webp) enabled in the site options for images already uploaded, the originals and their intermediate sizes. Images uploaded after the option was turned on get them automatically. Without ids it works on the image attachments in ID order, up to limit. Use missing to process only the images without formats. Returns the images done and how many images are still without formats.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer', 'minimum' => 1],
                        'maxItems' => 50,
                        'description' => 'IDs of image attachments to process. Other attachments are skipped.',
                    ],
                    'missing' => ['type' => 'boolean', 'description' => 'Process only the images without generated formats.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20, 'description' => 'Maximum number of images to process when ids is not given.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'done' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'formats' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                        ],
                        'description' => 'Images processed, with the formats generated for each.',
                    ],
                    'enabled' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Formats enabled in the site options.'],
                    'supported' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Formats the image editor of the server can write.'],
                    'remaining' => ['type' => 'integer', 'description' => 'Number of image attachments still without formats.'],
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
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $enabled = ImageFormats::enabled();
        if ($enabled === []) {
            return ['ok' => false, 'error' => ['code' => 'formats_disabled', 'message' => __('No image format is enabled: turn on avif or webp in the site options (imageFormats) first.', 'nucleo')]];
        }

        $images = self::imageIds();
        $targets = $images;
        if (is_array($input['ids'] ?? null)) {
            $imageSet = array_flip($images);
            $targets = array_values(array_unique(array_filter(
                array_map('intval', $input['ids']),
                static fn(int $id): bool => isset($imageSet[$id])
            )));
        }
        if (($input['missing'] ?? false) === true) {
            $targets = array_values(array_filter($targets, static fn(int $id): bool => self::lacksFormats($id, $enabled)));
        }
        if (!is_array($input['ids'] ?? null)) {
            $limit = min(50, max(1, (int) ($input['limit'] ?? 20)));
            $targets = array_slice($targets, 0, $limit);
        }

        $done = [];
        foreach ($targets as $id) {
            $done[] = ['id' => $id, 'formats' => array_keys(ImageFormats::generate($id))];
        }

        $remaining = count(array_filter($images, static fn(int $id): bool => self::lacksFormats($id, $enabled)));

        return [
            'ok' => true,
            'done' => $done,
            'enabled' => $enabled,
            'supported' => ImageFormats::supported(),
            'remaining' => $remaining,
        ];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('upload_files');
    }

    /**
     * ID di tutti gli allegati immagine, in ordine di ID.
     * @return list<int>
     */
    private static function imageIds(): array
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image',
            'orderby' => 'ID',
            'order' => 'ASC',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        return array_values(array_map('intval', $ids));
    }

    /**
     * true se l'immagine non ha ancora nessuno dei formati accesi.
     * @param list<string> $enabled
     */
    private static function lacksFormats(int $id, array $enabled): bool
    {
        $meta = get_post_meta($id, ImageFormats::META, true);
        if (!is_array($meta)) {
            return true;
        }
        foreach ($enabled as $format) {
            if (!empty($meta[$format])) {
                return false;
            }
        }

        return true;
    }
}
