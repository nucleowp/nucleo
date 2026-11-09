<?php

declare(strict_types=1);

namespace Nucleo\Blocks;

interface DynamicRenderer
{
    /**
     * HTML for one slot at request time. Must use only data WordPress already loaded for the request.
     * @param array<string,mixed> $data the data stored with the slot at compile time
     */
    public function renderSlot(array $data): string;
}
