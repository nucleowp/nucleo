<?php

declare(strict_types=1);

namespace Nucleo\Assets;

use Nucleo\Services;

/**
 * Critical CSS per template, nell'artefatto 'critical' ({template => css}).
 */
final class CriticalCss
{
    private const KEY = 'critical';
    /** Limite per template: 50 KB. */
    public const MAX_BYTES = 51200;

    /**
     * @throws \InvalidArgumentException se il template è vuoto o il CSS pulito supera 50 KB
     */
    public static function set(string $template, string $css): void
    {
        if ($template === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException(__('The template is required.', 'nucleo'));
        }
        // "</style" chiuderebbe il tag inline: lo tolgo dal CSS.
        $clean = (string) preg_replace('#</style#i', '', $css);
        if (strlen($clean) > self::MAX_BYTES) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException(__('The critical CSS exceeds the 50 KB limit.', 'nucleo'));
        }

        $map = self::map();
        $map[$template] = $clean;
        Services::store()->put(self::KEY, $map);
    }

    public static function get(string $template): ?string
    {
        $css = self::map()[$template] ?? null;

        return is_string($css) && $css !== '' ? $css : null;
    }

    /** Con null rimuove tutti i template. */
    public static function clear(?string $template): void
    {
        if ($template === null) {
            Services::store()->delete(self::KEY);

            return;
        }

        $map = self::map();
        unset($map[$template]);
        Services::store()->put(self::KEY, $map);
    }

    /**
     * Le chiavi vecchie (post, category, product_cat) si leggono come quelle di TemplateKey;
     * se esiste già la chiave nuova, vince quella.
     *
     * @return array<string, string>
     */
    private static function map(): array
    {
        $raw = Services::store()->get(self::KEY) ?? [];
        $map = [];
        foreach ($raw as $template => $css) {
            $key = (string) $template;
            if (is_string($css) && RulesPublisher::templateKey($key) === $key) {
                $map[$key] = $css;
            }
        }
        foreach ($raw as $template => $css) {
            if (is_string($css)) {
                $map[RulesPublisher::templateKey((string) $template)] ??= $css;
            }
        }

        return $map;
    }
}
