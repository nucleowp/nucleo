<?php

declare(strict_types=1);

namespace Nucleo\Blocks;

/** Immutable view of one document node. Arrays keep the document shape (see schema/document.schema.json). */
final class Node
{
    /**
     * @param array<string,mixed> $content
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $style
     * @param array<string,mixed> $visibility
     * @param array<string,mixed> $bind
     * @param list<Node> $children
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $content = [],
        public readonly array $settings = [],
        public readonly array $style = [],
        public readonly array $visibility = [],
        public readonly array $bind = [],
        public readonly array $children = [],
        public readonly ?string $ref = null,
    ) {
    }
}
