<?php

declare(strict_types=1);

namespace Nucleo\Blocks;

interface Renderer
{
    /** Static HTML of the node. Called at save time only. Use $ctx->renderChildren() for children. */
    public function render(Node $node, RenderContext $ctx): string;
}
