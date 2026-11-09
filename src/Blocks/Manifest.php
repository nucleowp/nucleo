<?php

declare(strict_types=1);

namespace Nucleo\Blocks;

/** Parsed block.nucleo.json (see schema/block.schema.json). */
final class Manifest
{
    /**
     * @param array<string,array<string,mixed>> $content property name → property schema
     * @param array<string,array<string,mixed>> $settings
     * @param array<string,mixed> $supports keys: style (list<string>), responsive, visibility, bind
     * @param bool|list<string> $children true = any, false = none, list = allowed types
     * @param list<string>|null $parents
     * @param array{style?:string|null,script?:string|null,strategy?:string} $assets paths relative to $dir; strategy eager|visible|interaction|idle
     * @param array<string,mixed> $semantics
     * @param list<string> $deps invalidation keys, templates like "menu:{settings.menu}"
     */
    public function __construct(
        public readonly string $name,
        public readonly int $version,
        public readonly string $title,
        public readonly string $dir,
        public readonly string $category = 'general',
        public readonly string $description = '',
        public readonly string $aiHints = '',
        public readonly array $content = [],
        public readonly array $settings = [],
        public readonly array $supports = [],
        public readonly bool|array $children = false,
        public readonly ?array $parents = null,
        public readonly array $assets = [],
        public readonly array $semantics = [],
        public readonly string $renderer = '',
        public readonly array $deps = [],
        /** @var array<string,string> parti stilizzabili: nome → selettore dentro il nodo (" legend", " input::placeholder") */
        public readonly array $parts = [],
    ) {
    }
}
