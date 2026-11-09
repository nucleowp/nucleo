<?php

declare(strict_types=1);

namespace Nucleo\Storage;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Nucleo\Support\Filesystem;

/**
 * Stores artifacts as versioned PHP files "<key>.<hash8>.php" that are read with include,
 * so OPcache can serve them. Writes go to a temporary file in the same directory and are
 * renamed into place, so readers never see a partial artifact.
 */
final class PhpFileStore implements ArtifactStore
{
    private const KEY_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,63}\z/';
    private const FILE_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,63}\.[0-9a-f]{8}\.php\z/';

    private string $dir;

    /** @var array<string, array<mixed>|null> */
    private array $memo = [];

    /** @var (\Closure(): string)|null cartella della generazione, chiesta al primo put */
    private ?\Closure $claim;

    /** @param (callable(): string)|null $claim restituisce la cartella in cui scrivere da quel momento */
    public function __construct(string $dir, ?callable $claim = null)
    {
        $this->dir = $this->prepare($dir);
        $this->claim = $claim === null ? null : \Closure::fromCallable($claim);
    }

    public function get(string $key): ?array
    {
        self::assertKey($key);
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        $value = null;
        $files = $this->versions($key);
        if ($files !== []) {
            $value = $this->load($files[0]);
        }
        $this->memo[$key] = $value;

        return $value;
    }

    public function put(string $key, array $value): void
    {
        self::assertKey($key);
        self::assertStorable($value);
        if ($this->claim !== null) {
            $claim = $this->claim;
            $this->claim = null;
            $this->dir = $this->prepare($claim());
            $this->memo = [];
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- genera il file PHP della cache (OPcache), non è codice di debug.
        $code = "<?php defined('ABSPATH') || exit; return " . var_export($value, true) . ';';
        $file = $this->dir . '/' . $key . '.' . substr(sha1($code), 0, 8) . '.php';

        $tmp = @tempnam($this->dir, '.tmp-');
        if ($tmp === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new RuntimeException("Cannot create temporary file in {$this->dir}");
        }
        try {
            $this->writeFile($tmp, $code);
            $fs = Filesystem::direct();
            $fs->chmod($tmp, 0644);
            if (!$fs->move($tmp, $file, true)) {
                throw new RuntimeException("Cannot move artifact into place: $file");
            }
        } finally {
            if (is_file($tmp)) {
                Filesystem::direct()->delete($tmp);
            }
        }

        $this->removeVersions($key, $file);
        $this->memo[$key] = $value;
    }

    public function delete(string $key): void
    {
        self::assertKey($key);
        $this->removeVersions($key);
        unset($this->memo[$key]);
    }

    public function flush(): void
    {
        foreach (glob($this->dir . '/*.php') ?: [] as $file) {
            if (preg_match(self::FILE_PATTERN, basename($file)) === 1) {
                $this->unlinkFile($file);
            }
        }
        $this->memo = [];
    }

    /** Cartella in cui lo store legge e scrive. */
    public function dir(): string
    {
        return $this->dir;
    }

    /** Crea la cartella con index.php e .htaccess. */
    private function prepare(string $dir): string
    {
        $dir = rtrim($dir, '/\\');
        if (!wp_mkdir_p($dir)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new RuntimeException("Cannot create artifact directory: {$dir}");
        }
        if (!wp_is_writable($dir)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new RuntimeException("Artifact directory is not writable: {$dir}");
        }
        if (!is_file($dir . '/index.php')) {
            $this->writeFile($dir . '/index.php', '');
        }
        if (!is_file($dir . '/.htaccess')) {
            $this->writeFile($dir . '/.htaccess', "Require all denied\n");
        }

        return $dir;
    }

    public function driver(): string
    {
        return 'php-file';
    }

    /** @return list<string> Versions of the key, newest first. */
    private function versions(string $key): array
    {
        $files = glob($this->dir . '/' . $key . '.*.php') ?: [];
        clearstatcache();
        usort($files, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

        return $files;
    }

    private function removeVersions(string $key, ?string $keep = null): void
    {
        foreach ($this->versions($key) as $file) {
            if ($file !== $keep) {
                $this->unlinkFile($file);
            }
        }
    }

    private function unlinkFile(string $file): void
    {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
        if (is_file($file)) {
            Filesystem::direct()->delete($file);
        }
    }

    /** @return array<mixed>|null */
    private function load(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        try {
            $value = (static fn (string $f): mixed => @include $f)($file);
        } catch (Throwable) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    private function writeFile(string $path, string $data): void
    {
        if (@file_put_contents($path, $data) === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new RuntimeException("Cannot write file: $path");
        }
    }

    private static function assertKey(string $key): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException("Invalid artifact key: \"$key\"");
        }
    }

    /** Only scalars, nulls and nested arrays can be exported as PHP code.
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
