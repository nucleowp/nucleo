<?php

declare(strict_types=1);

namespace Nucleo\Extend;

defined('ABSPATH') || exit;

/** Contesto di un'azione: modulo, documento, post della pagina e IP del visitatore. */
final class ActionContext
{
    public function __construct(
        public readonly string $formId,
        public readonly int $documentId,
        public readonly int $contextPostId,
        public readonly string $ip,
    ) {
    }
}
