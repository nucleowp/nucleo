<?php

declare(strict_types=1);

namespace Nucleo\Blocks;

/** Services a Renderer may use while compiling. */
interface RenderContext
{
    /** class attribute value for a node: "nc-<type-slug> " + style classes from CssResult. */
    public function classes(Node $node, string ...$extra): string;

    public function renderChildren(Node $node): string;

    /** @param array<string,mixed> $data
     * Creates a dynamic slot: returns "<!--nc:s N-->$fallbackHtml<!--/nc:s-->" and records [N => [block, data]]. */
    public function slot(string $blockType, array $data, string $fallbackHtml): string;

    /** True for the first image-like node in document order (LCP candidate). Each call with a new id after the first returns false. */
    public function claimLcp(string $nodeId): bool;

    /** Escapes and sanitizes inline rich text (strong, em, a, br, span) for output. */
    public function richText(string $html): string;

    /**
     * Compila una scheda: il sottoalbero diventa un modello con slot propri, da riempire per ogni elemento con
     * Runtime\CardRenderer. Ogni bind resta dinamico; gli slot non entrano nell'indice della pagina.
     * @return array{html:string,slots:array<int,array{block:string,data:array<string,mixed>}>}
     */
    public function template(Node $card): array;

    /** Token-aware value: "$color.primary" → "var(--nc-color-primary)", literals unchanged. */
    public function value(string $v): string;
}
