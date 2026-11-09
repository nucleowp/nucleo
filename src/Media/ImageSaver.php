<?php

declare(strict_types=1);

namespace Nucleo\Media;

/** Salva un'immagine nella libreria media: limite di dimensione, tipo reale del contenuto, testo alternativo. */
final class ImageSaver
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** MIME ammessi, con l'estensione del file da salvare. */
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
    ];

    /**
     * Salva il contenuto come allegato. $name è il nome senza estensione: l'estensione viene dal tipo reale.
     * @return array<string,mixed> ok, id, url, width, height oppure error
     */
    public static function save(string $body, string $name, string $alt, ?string $title): array
    {
        // Il limite di WordPress tronca in silenzio: un corpo che arriva al limite è considerato troppo grande.
        if ($body === '' || strlen($body) >= self::MAX_BYTES) {
            return self::failure('download_failed', __('Image is empty or over 10 MB.', 'nucleo'));
        }

        // Si controlla il contenuto reale, non l'header Content-Type dichiarato dal server.
        $info = getimagesizefromstring($body);
        $mime = is_array($info) ? $info['mime'] : '';
        if (!is_array($info) || !isset(self::TYPES[$mime])) {
            return self::failure('unsupported_type', __('The content is not a jpeg, png, webp, gif or avif image.', 'nucleo'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = wp_tempnam('nucleo-media');
        if ($tmp === false || file_put_contents($tmp, $body) === false) {
            return self::failure('download_failed', __('Unable to save the temporary file.', 'nucleo'));
        }

        $base = sanitize_file_name($name);
        $fileName = ($base !== '' ? $base : 'image') . '.' . self::TYPES[$mime];
        $postData = $title !== null && $title !== '' ? ['post_title' => sanitize_text_field($title)] : [];

        $id = media_handle_sideload(['name' => $fileName, 'tmp_name' => $tmp], 0, null, $postData);
        wp_delete_file($tmp);
        if (is_wp_error($id)) {
            return self::failure('download_failed', sprintf(/* translators: %s: error message from the media library */ __('Saving to the media library failed: %s', 'nucleo'), $id->get_error_message()));
        }

        update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($alt));

        return [
            'ok' => true,
            'id' => $id,
            'url' => (string) wp_get_attachment_url($id),
            'width' => (int) $info[0],
            'height' => (int) $info[1],
        ];
    }

    /** @return array{ok:false,error:array{code:string,message:string}} */
    private static function failure(string $code, string $message): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    }
}
