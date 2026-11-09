<?php

declare(strict_types=1);

namespace Nucleo\Sections;

use Nucleo\Schema\ValidationError;

/** Esito dell'espansione dei blocchi nc/section-ref di un documento. */
final class Expansion
{
    /**
     * @param array<string,mixed> $doc documento con le sezioni espanse e i bind "param" risolti
     * @param list<ValidationError> $errors parametri mancanti, non dichiarati o del tipo sbagliato, cicli, profondità
     * @param list<array{id:string,section:string}> $missing nodi nc/section-ref del documento con sezione assente: id del nodo e slug
     * @param list<string> $deps "section:<id>" per le sezioni trovate, "section-slug:<slug>" per ogni slug citato
     * @param array<string,string> $origins id di un nodo espanso → id del nodo nc/section-ref del documento che lo contiene
     * @param array<string,array<string,string>> $bound id di un nodo espanso → chiave del bind ("content.text") → nome del parametro
     *        del nc/section-ref del documento che ha fornito il valore (vuoto se il valore viene da una sezione annidata)
     * @param list<array{id:string,ref:string}> $links riferimenti dei link senza contenuto (Compiler\LinkRefs)
     * @param list<array{id:string,href:string}> $paths link interni scritti come percorso
     * @param array<string,string> $targets bersaglio di ogni riferimento risolto (LinkTargets::key → "link:post:<id>")
     */
    public function __construct(
        public readonly array $doc,
        public readonly array $errors = [],
        public readonly array $missing = [],
        public readonly array $deps = [],
        public readonly array $origins = [],
        public readonly array $bound = [],
        public readonly array $links = [],
        public readonly array $paths = [],
        public readonly array $targets = [],
    ) {
    }
}
