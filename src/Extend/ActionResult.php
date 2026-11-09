<?php

declare(strict_types=1);

namespace Nucleo\Extend;

/** Esito di un'azione. message è per il visitatore; data resta sul server. */
final class ActionResult
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message = '',
        public readonly array $data = [],
    ) {
    }

    public static function ok(string $message = ''): self
    {
        return new self(true, $message);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }
}
