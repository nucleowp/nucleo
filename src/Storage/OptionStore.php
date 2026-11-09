<?php

declare(strict_types=1);

namespace Nucleo\Storage;

use InvalidArgumentException;

/**
 * Stores artifacts in WordPress options "nucleo_art_<key>", never autoloaded.
 * The keys written so far are listed in "nucleo_art__index", so flush() removes only this store's options.
 */
final class OptionStore implements ArtifactStore
{
    private const KEY_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,63}\z/';
    private const PREFIX = 'nucleo_art_';
    private const INDEX = 'nucleo_art__index';

    public function get(string $key): ?array
    {
        self::assertKey($key);
        $value = get_option(self::PREFIX . $key, null);

        return is_array($value) ? $value : null;
    }

    public function put(string $key, array $value): void
    {
        self::assertKey($key);
        self::assertStorable($value);

        update_option(self::PREFIX . $key, $value, false);
        $keys = $this->index();
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            update_option(self::INDEX, $keys, false);
        }
    }

    public function delete(string $key): void
    {
        self::assertKey($key);
        delete_option(self::PREFIX . $key);
        $keys = $this->index();
        if (in_array($key, $keys, true)) {
            update_option(self::INDEX, array_values(array_diff($keys, [$key])), false);
        }
    }

    public function flush(): void
    {
        foreach ($this->index() as $key) {
            delete_option(self::PREFIX . $key);
        }
        delete_option(self::INDEX);
    }

    public function driver(): string
    {
        return 'option';
    }

    /** @return list<string> */
    private function index(): array
    {
        $keys = get_option(self::INDEX, []);

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    private static function assertKey(string $key): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException("Invalid artifact key: \"$key\"");
        }
    }

    /** Only scalars, nulls and nested arrays are accepted, as in the file store.
     * @param array<mixed> $value */
    private static function assertStorable(array $value): void
    {
        array_walk_recursive($value, static function (mixed $leaf): void {
            if (is_object($leaf) || is_resource($leaf)) {
                throw new InvalidArgumentException('Artifact values cannot contain objects or resources');
            }
            if (is_float($leaf) && !is_finite($leaf)) {
                throw new InvalidArgumentException('Artifact values cannot contain NAN or INF');
            }
        });
    }
}
