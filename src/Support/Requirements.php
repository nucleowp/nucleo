<?php

declare(strict_types=1);

namespace Nucleo\Support;

defined('ABSPATH') || exit;

/** Environment checks run before the plugin boots. Only "php" and "wp" block. */
final class Requirements
{
    /** @param array<string,string> $writableDirs label => path */
    public function __construct(
        private readonly string $phpVersion,
        private readonly string $wpVersion,
        private readonly bool $opcache,
        private readonly array $writableDirs,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $wp = $GLOBALS['wp_version'] ?? '0';
        $dirs = defined('WP_CONTENT_DIR') ? ['content' => WP_CONTENT_DIR] : [];

        return new self(
            PHP_VERSION,
            is_string($wp) ? $wp : '0',
            function_exists('opcache_get_status') && is_array(opcache_get_status(false)),
            $dirs,
        );
    }

    /** @return list<RequirementResult> */
    public function check(): array
    {
        $minPhp = defined('NUCLEO_MIN_PHP') ? NUCLEO_MIN_PHP : '8.2';
        $minWp = defined('NUCLEO_MIN_WP') ? NUCLEO_MIN_WP : '6.9';

        $results = [
            $this->minimum('php', 'PHP', $this->phpVersion, $minPhp),
            $this->minimum('wp', 'WordPress', $this->wpVersion, $minWp),
            new RequirementResult(
                'opcache',
                $this->opcache,
                false,
                $this->opcache ? 'OPcache enabled' : __('OPcache is not enabled', 'nucleo'),
            ),
        ];

        foreach ($this->writableDirs as $label => $path) {
            $ok = is_dir($path) && wp_is_writable($path);
            $results[] = new RequirementResult(
                'dir:' . (string) $label,
                $ok,
                false,
                $ok
                    ? sprintf('%s writable', (string) $label)
                    /* translators: 1: folder label, 2: path. */
                    : sprintf(__('%1$s is not writable: %2$s', 'nucleo'), (string) $label, $path),
            );
        }

        return $results;
    }

    private function minimum(string $id, string $label, string $found, string $min): RequirementResult
    {
        $ok = version_compare($found, $min, '>=');

        return new RequirementResult(
            $id,
            $ok,
            true,
            $ok
                ? sprintf('%s %s', $label, $found)
                /* translators: 1: software name, 2: installed version, 3: minimum version. */
                : sprintf(__('%1$s %2$s is below the minimum %3$s', 'nucleo'), $label, $found, $min),
        );
    }
}
