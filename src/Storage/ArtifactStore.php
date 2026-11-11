<?php

declare(strict_types=1);

namespace Nucleo\Storage;

defined('ABSPATH') || exit;

/**
 * Stores precomputed PHP arrays ("artifacts") that the runtime reads on every request.
 * Keys match /^[a-z0-9][a-z0-9_-]{0,63}$/. Values are arrays of scalars, nulls and nested arrays only.
 */
interface ArtifactStore
{
    /** @return array<mixed>|null Null when the artifact is missing or unreadable. */
    public function get(string $key): ?array;

    /** @param array<mixed> $value Writes atomically; readers never see a partial artifact. */
    public function put(string $key, array $value): void;

    public function delete(string $key): void;

    /** Removes every artifact owned by this store. */
    public function flush(): void;

    /** Driver id: "php-file" or "option". */
    public function driver(): string;
}
