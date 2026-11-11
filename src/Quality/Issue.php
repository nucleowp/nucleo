<?php

declare(strict_types=1);

namespace Nucleo\Quality;

defined('ABSPATH') || exit;

final class Issue
{
    /**
     * @param list<array{doc:string,id:string}> $nodes
     * @param list<array<string,mixed>> $fix semantic patch ops, each with an extra "doc" key
     * @param bool $publishBlocking avviso che blocca la pubblicazione come un errore, salvo acknowledge
     */
    public function __construct(
        public readonly string $rule,
        public readonly Severity $severity,
        public readonly string $message,
        public readonly array $nodes = [],
        public readonly array $fix = [],
        public readonly bool $publishBlocking = false,
    ) {
    }
}
