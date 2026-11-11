<?php

declare(strict_types=1);

namespace Nucleo\Quality;

defined('ABSPATH') || exit;

/**
 * Semantic outline of the page as the visitor sees it: header + layout + content + footer.
 * Built from documents, never from HTML.
 */
final class ComposedPage
{
    /**
     * @param list<array{doc:string,id:string,level:int,text:string,visible:array<string,bool>}> $headings document order
     * @param list<array{doc:string,id:string,src:string,alt:string|null,decorative:bool,lazy:bool,lcp:bool}> $images
     * @param list<array{doc:string,id:string,href:string,text:string,ariaLabel:string|null}> $links
     * @param list<array{doc:string,id:string,breakpoint:string,fg:string,bg:string,large:bool,graphic:bool}> $colorPairs resolved hex colors, uno per breakpoint; graphic: icona con etichetta (WCAG 1.4.11)
     * @param list<string> $landmarks e.g. header, nav, main, footer
     * @param list<array{doc:string,id:string,when:list<string>}> $conditional nodi con visibility.when
     * @param list<array{doc:string,id:string,anchor:string}> $anchors nodi con settings.anchor non vuoto, in ordine di documento
     * @param list<array{doc:string,id:string,zIndex:bool}> $fixed nodi con position fixed in almeno un breakpoint
     * @param list<array{doc:string,id:string,text:string,href:string}> $buttons nodi nc/button, con href grezzo ('' se assente)
     * @param list<array{doc:string,id:string,prop:string,value:string}> $styles valori stringa di style, uno per breakpoint
     * @param list<array{doc:string,id:string,actions:list<array{type:string,target:string}>}> $forms moduli con azioni esterne
     * @param list<array{doc:string,id:string,area:string,conditions:string}> $layouts layout composti: id della radice, area e condizioni (JSON)
     * @param list<array{doc:string,id:string,type:string,node:array<string,mixed>,visible:array<string,bool>}> $nodes tutti i nodi in ordine di documento, senza children; visible combina il nodo e i suoi antenati
     * @param array{css:int,js:int}|null $weight byte di CSS e JS che Nucleo serve alla pagina; null se non misurabile (senza WordPress o senza post)
     */
    public function __construct(
        public readonly array $headings = [],
        public readonly array $images = [],
        public readonly array $links = [],
        public readonly array $colorPairs = [],
        public readonly array $landmarks = [],
        public readonly array $conditional = [],
        public readonly array $anchors = [],
        public readonly array $fixed = [],
        public readonly array $buttons = [],
        public readonly array $styles = [],
        public readonly array $forms = [],
        public readonly array $layouts = [],
        public readonly array $nodes = [],
        public readonly ?array $weight = null,
    ) {
    }
}
