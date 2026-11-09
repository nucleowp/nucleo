<?php

declare(strict_types=1);

namespace Nucleo\Quality\RuleSets;

defined('ABSPATH') || exit;

use Nucleo\Quality\Rules\ButtonAsLink;
use Nucleo\Quality\Rules\FocusHidden;
use Nucleo\Quality\Rules\LangMissing;
use Nucleo\Quality\Rules\TargetSize;
use Nucleo\Quality\Rules\VideoAutoplay;

// Restituisce list<\Nucleo\Quality\Rule>.

return [
    new ButtonAsLink(),
    new FocusHidden(),
    new TargetSize(),
    new LangMissing(),
    new VideoAutoplay(),
];
