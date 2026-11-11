<?php

declare(strict_types=1);

namespace Nucleo\Support;

defined('ABSPATH') || exit;

/** One environment check. $blocking=true means the plugin must not boot. */
final class RequirementResult
{
    public function __construct(
        public readonly string $id,
        public readonly bool $ok,
        public readonly bool $blocking,
        public readonly string $message,
    ) {
    }
}
