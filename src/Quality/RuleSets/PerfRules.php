<?php

declare(strict_types=1);

namespace Nucleo\Quality\RuleSets;

defined('ABSPATH') || exit;

use Nucleo\Quality\Rules\CssBudget;
use Nucleo\Quality\Rules\DomSize;
use Nucleo\Quality\Rules\FontCount;
use Nucleo\Quality\Rules\ImageOversize;
use Nucleo\Quality\Rules\JsBudget;

// Restituisce list<\Nucleo\Quality\Rule>.

return [
    new ImageOversize(),
    new DomSize(),
    new CssBudget(),
    new JsBudget(),
    new FontCount(),
];
