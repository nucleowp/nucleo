<?php

declare(strict_types=1);

namespace Nucleo\Quality\RuleSets;

defined('ABSPATH') || exit;

use Nucleo\Quality\Rules\HeadingStyleOnly;
use Nucleo\Quality\Rules\HiddenContent;
use Nucleo\Quality\Rules\ImageFilename;
use Nucleo\Quality\Rules\LinkTextGeneric;

// Restituisce list<\Nucleo\Quality\Rule>.

return [
    new HeadingStyleOnly(),
    new LinkTextGeneric(),
    new ImageFilename(),
    new HiddenContent(),
];
