<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Immagine di contenuto senza alt. Le immagini decorative sono escluse. */
final class ImageAltMissing implements Rule
{
    public function id(): string
    {
        return 'seo.image.alt.missing';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->images as $image) {
            if ($image['decorative'] || ($image['alt'] !== null && trim($image['alt']) !== '')) {
                continue;
            }
            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                __('Image without alternative text.', 'nucleo'),
                [['doc' => $image['doc'], 'id' => $image['id']]],
            );
        }

        return $issues;
    }
}
