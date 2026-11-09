<?php

declare(strict_types=1);

namespace Nucleo\Quality;

interface Rule
{
    public function id(): string;

    public function severity(): Severity;

    /** @return list<Issue> */
    public function check(ComposedPage $page): array;
}
