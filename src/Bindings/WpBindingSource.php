<?php

declare(strict_types=1);

namespace Nucleo\Bindings;

defined('ABSPATH') || exit;

/**
 * Valori fissi di un documento WordPress, letti in compilazione.
 * Solo le pagine hanno valori statici: layout e sezioni dipendono dal contesto di rendering.
 */
final class WpBindingSource implements BindingSource
{
    /** Sorgenti che dipendono dalla richiesta: non si leggono in compilazione. */
    private const RUNTIME_SOURCES = ['user', 'query', 'woo', 'term', 'item'];

    public function __construct(
        private readonly int $postId,
        private readonly string $kind,
    ) {
    }

    public function staticValue(string $source, string $field): ?string
    {
        if ($this->kind !== 'page' || in_array($source, self::RUNTIME_SOURCES, true)) {
            return null;
        }

        return BindingResolver::value($source, $field, $this->postId);
    }
}
