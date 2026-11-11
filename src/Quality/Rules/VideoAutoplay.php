<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

defined('ABSPATH') || exit;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Video incorporato che parte da solo senza essere muto: disturba chi usa lo screen reader. */
final class VideoAutoplay implements Rule
{
    public function id(): string
    {
        return 'a11y.video.autoplay';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->nodes as $entry) {
            if ($entry['type'] !== 'nc/embed') {
                continue;
            }

            $settings = is_array($entry['node']['settings'] ?? null) ? $entry['node']['settings'] : [];
            $url = $settings['url'] ?? null;
            if (!is_string($url)) {
                continue;
            }

            $query = wp_parse_url($url, PHP_URL_QUERY);
            if (!is_string($query)) {
                continue;
            }
            parse_str($query, $params);

            $autoplay = in_array($params['autoplay'] ?? null, ['1', 'true'], true);
            $muted = ($params['mute'] ?? null) === '1' || in_array($params['muted'] ?? null, ['1', 'true'], true);
            if (!$autoplay || $muted) {
                continue;
            }

            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                __('The embedded video starts automatically with sound: add mute=1 or remove autoplay.', 'nucleo'),
                [['doc' => $entry['doc'], 'id' => $entry['id']]],
            );
        }

        return $issues;
    }
}
