<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Media\ImageSaver;

/** nucleo/media-upload: carica un'immagine inviata in base64 nella libreria media, con testo alternativo. */
final class MediaUpload implements AbilityDefinition
{
    /** Meta dell'allegato con lo sha1 del contenuto, per riconoscere un caricamento identico. */
    private const SHA1_META = '_nucleo_sha1';

    public function name(): string
    {
        return 'nucleo/media-upload';
    }

    public function args(): array
    {
        return [
            'label' => __('Upload image', 'nucleo'),
            'description' => 'Uploads an image sent as base64 to the media library, with alt text. Accepts only jpeg, png, webp, gif and avif, up to 10 MB. An identical file already in the library is returned without uploading it again.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['file', 'data'],
                'properties' => [
                    'file' => ['type' => 'string', 'minLength' => 1, 'description' => 'File name with extension, for example photo.png.'],
                    'data' => ['type' => 'string', 'minLength' => 1, 'description' => 'Image content encoded in base64.'],
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
                    'file' => ['type' => 'string', 'description' => 'Name of the saved file.'],
                    'sha1' => ['type' => 'string', 'description' => 'SHA-1 of the decoded content.'],
                    'existing' => ['type' => 'boolean', 'description' => 'True when an identical attachment was already in the library and was not uploaded again.'],
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
        $file = $input['file'] ?? null;
        $data = $input['data'] ?? null;
        $alt = $input['alt'] ?? '';
        $title = $input['title'] ?? null;
        if (!is_string($file) || $file === '' || !is_string($data) || !is_string($alt) || ($title !== null && !is_string($title))) {
            return new \WP_Error('invalid_input', __('Fields file, data, alt or title are not valid.', 'nucleo'));
        }

        $bytes = base64_decode($data, true);
        if ($bytes === false || $bytes === '') {
            return self::failure('invalid_data', __('The image data is not valid base64.', 'nucleo'));
        }

        // Si controlla il contenuto reale: il tipo dà l'estensione e il nome previsto per il file salvato.
        $info = getimagesizefromstring($bytes);
        $mime = is_array($info) ? $info['mime'] : '';
        if (!is_array($info) || !isset(ImageSaver::TYPES[$mime])) {
            return self::failure('unsupported_type', __('The content is not a jpeg, png, webp, gif or avif image.', 'nucleo'));
        }

        $stem = sanitize_file_name(pathinfo(basename($file), PATHINFO_FILENAME));
        $stem = $stem !== '' ? $stem : 'image';
        $ext = ImageSaver::TYPES[$mime];
        $sha1 = sha1($bytes);

        $existing = self::findExisting($stem, $ext, $sha1);
        if ($existing !== null) {
            return [
                'ok' => true,
                'id' => $existing,
                'url' => (string) wp_get_attachment_url($existing),
                'width' => (int) $info[0],
                'height' => (int) $info[1],
                'file' => wp_basename((string) (wp_get_original_image_path($existing) ?: get_attached_file($existing))),
                'sha1' => $sha1,
                'existing' => true,
            ];
        }

        $saved = ImageSaver::save($bytes, $stem, $alt, $title);
        if ($saved['ok'] !== true) {
            return $saved;
        }

        $id = (int) $saved['id'];
        update_post_meta($id, self::SHA1_META, $sha1);

        return [
            'ok' => true,
            'id' => $id,
            'url' => $saved['url'],
            'width' => $saved['width'],
            'height' => $saved['height'],
            'file' => wp_basename((string) (wp_get_original_image_path($id) ?: get_attached_file($id))),
            'sha1' => $sha1,
            'existing' => false,
        ];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('upload_files');
    }

    /**
     * Allegato con lo stesso sha1 e il file salvato con il nome previsto. WordPress può aggiungere -1, -2 se il nome è occupato.
     * Si confronta il file originale: per le immagini oltre 2560 px il file allegato è la copia ridotta "-scaled".
     */
    private static function findExisting(string $stem, string $ext, string $sha1): ?int
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => self::SHA1_META,
            'meta_value' => $sha1,
        ]);
        $pattern = '/^' . preg_quote($stem, '/') . '(-\d+)?\.' . preg_quote($ext, '/') . '$/';
        foreach ($ids as $id) {
            if (preg_match($pattern, wp_basename((string) (wp_get_original_image_path((int) $id) ?: get_attached_file((int) $id)))) === 1) {
                return (int) $id;
            }
        }

        return null;
    }

    /** @return array{ok:false,error:array{code:string,message:string}} */
    private static function failure(string $code, string $message): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    }
}
