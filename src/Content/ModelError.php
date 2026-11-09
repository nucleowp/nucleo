<?php

declare(strict_types=1);

namespace Nucleo\Content;

/** Errore di validazione del modello dei contenuti. Gli errori dello schema JSON hanno code "schema". */
final class ModelError
{
    public function __construct(
        public readonly string $code,
        public readonly string $path,
        public readonly string $message,
    ) {
    }

    /** @return array{code:string,path:string,message:string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'path' => $this->path, 'message' => $this->message];
    }
}
