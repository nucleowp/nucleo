<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

final class SaveOptions
{
    /**
     * @param list<array{rule:string,node:string,reason:string}> $acknowledge
     * @param string $slug post_name alla creazione; vuoto: WordPress lo ricava dal titolo
     * @param ?int $parent post_parent da scrivere (0 = primo livello); null lascia il genitore com'è
     * @param string $status stato della bozza se non si pubblica: draft, pending o private; vuoto lascia lo stato com'è
     */
    public function __construct(
        public readonly bool $publish = false,
        public readonly bool $dryRun = false,
        public readonly array $acknowledge = [],
        public readonly ?int $baseRevision = null,
        public readonly string $title = '',
        public readonly string $postType = 'page',
        public readonly string $slug = '',
        public readonly ?int $parent = null,
        public readonly string $status = '',
    ) {
    }
}
