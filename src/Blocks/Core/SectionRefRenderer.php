<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;

/**
 * Il DocumentService sostituisce il blocco con l'albero della sezione prima della compilazione.
 * Il renderer agisce solo se la sezione manca: non stampa nulla, il motore qualità segnala il riferimento rotto.
 */
final class SectionRefRenderer implements Renderer
{
    public function render(Node $node, RenderContext $ctx): string
    {
        return '';
    }
}
