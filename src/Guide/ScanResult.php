<?php

declare(strict_types=1);

namespace Nucleo\Guide;

defined('ABSPATH') || exit;

/**
 * Conteggi della lettura dei documenti per la guida. samples: per contatore, fino a 3 posizioni "page:home#a12".
 */
final class ScanResult
{
    /**
     * @param array<string, list<string>> $samples chiavi uguali ai nomi delle proprietà
     * @param list<array{nodes:list<string>, size:int, outline:string}> $groups
     */
    public function __construct(
        /** valori di style uguali a un token (R1.2) */
        public readonly int $tokenLiterals = 0,
        /** sottoalberi di almeno 5 nodi con la stessa struttura, ripetuti almeno 2 volte (R1.5) */
        public readonly int $repeated = 0,
        /** documenti page/post con parti globali: nc/menu o nodo con settings.tag header/footer (R2.1) */
        public readonly int $globalParts = 0,
        /** link interni scritti come percorso "/..." (R2.3) */
        public readonly int $pathLinks = 0,
        /** gruppi di almeno 3 fratelli con la stessa struttura fuori da nc/query e nc/collection (R3.1) */
        public readonly int $handLists = 0,
        /** nc/button senza href (R4.5) */
        public readonly int $inertButtons = 0,
        /** nc/native-form */
        public readonly int $forms = 0,
        /** nc/query e nc/collection */
        public readonly int $lists = 0,
        /** nc/section-ref */
        public readonly int $sectionRefs = 0,
        public readonly array $samples = [],
        /** blocchi ripetuti (R1.5): per ogni forma le copie al livello più alto, i nodi che pesano e la sagoma */
        public readonly array $groups = [],
        /** nc/section con figli diretti misti, sezioni e altri blocchi (R1.7) */
        public readonly int $mixedSections = 0,
        /** nodi con "guide": {"distinct": ...}; i campioni sono "page:a#id: motivo" */
        public readonly int $distinct = 0,
    ) {
    }
}
