<?php

declare(strict_types=1);

namespace Nucleo\Blocks;

use Nucleo\Schema\ValidationError;

/** Controlli di un blocco oltre il manifest. DocumentValidator li chiama dopo le proprietà e i figli. */
interface NodeValidator
{
    /**
     * @param array<string,mixed> $node nodo normalizzato, con i figli
     * @return list<ValidationError>
     */
    public function validateNode(array $node, string $path): array;
}
