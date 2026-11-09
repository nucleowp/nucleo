<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Immagine con nome file generico della fotocamera o esadecimale, poco utile alla ricerca. */
final class ImageFilename implements Rule
{
    public function id(): string
    {
        return 'seo.image.filename';
    }

    public function severity(): Severity
    {
        return Severity::Info;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->images as $image) {
            if ($image['decorative'] || trim($image['src']) === '') {
                continue;
            }

            $path = wp_parse_url($image['src'], PHP_URL_PATH);
            $file = basename(is_string($path) ? $path : $image['src']);
            $name = pathinfo($file, PATHINFO_FILENAME);
            if ($name === '') {
                continue;
            }

            $generic = preg_match('/^(img|dsc|dscn|dcim|image|photo|pxl|screenshot|whatsapp image)[-_ ]?\d*$/i', $name) === 1;
            $hash = preg_match('/^[0-9a-f]{8,}$/i', $name) === 1;
            if (!$generic && !$hash) {
                continue;
            }

            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                __('Image file name is generic: use a descriptive name.', 'nucleo'),
                [['doc' => $image['doc'], 'id' => $image['id']]],
            );
        }

        return $issues;
    }
}
