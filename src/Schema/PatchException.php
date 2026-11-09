<?php

declare(strict_types=1);

namespace Nucleo\Schema;

/** Raised when a patch cannot apply. $index is the failing operation, $code one of: invalid_op, not_found, conflict, forbidden. */
final class PatchException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $index, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
