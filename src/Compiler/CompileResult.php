<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

final class CompileResult
{
    /**
     * @param string $html static HTML with slot markers
     * @param string $css instance CSS of the document (CssResult::$css)
     * @param list<string> $blockTypes distinct block types used, document order
     * @param array<int,array{block:string,data:array<string,mixed>}> $slots
     * @param array{styles:list<string>,scripts:list<array{type:string,src:string,strategy:string}>} $assets block asset paths
     * @param string|null $lcpNodeId
     * @param list<string> $deps dipendenze risolte dei nodi, senza duplicati
     */
    public function __construct(
        public readonly string $html,
        public readonly string $css,
        public readonly array $blockTypes,
        public readonly array $slots,
        public readonly array $assets,
        public readonly ?string $lcpNodeId,
        public readonly array $deps = [],
    ) {
    }
}
