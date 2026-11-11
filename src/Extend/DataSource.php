<?php

declare(strict_types=1);

namespace Nucleo\Extend;

defined('ABSPATH') || exit;

/**
 * Sorgente dati scritta dallo sviluppatore in un plugin del sito (livello 4).
 * I documenti la usano per nome in nc/collection ("source": "component"); l'MCP vede solo nome e schema.
 */
interface DataSource
{
    /** "<vendor>/<nome>": minuscole, cifre e trattini. */
    public function name(): string;

    public function label(): string;

    /**
     * params: chiave → JSON Schema del parametro (string, integer, number, boolean; enum per le opzioni).
     * fields: chiave → {type, label} dei campi di ogni elemento, letti dalla scheda con la sorgente "item".
     * ttl (facoltativo): secondi di cache dei risultati; 0 = niente cache; senza valore 300.
     * @return array{params: array<string,array<string,mixed>>, fields: array<string,array{type:string,label:string}>, ttl?: int}
     */
    public function schema(): array;

    /** @param array<string,mixed> $params già validati contro schema()['params'] */
    public function read(array $params, int $page, int $perPage): DataPage;
}
