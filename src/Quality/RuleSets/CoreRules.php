<?php

declare(strict_types=1);

namespace Nucleo\Quality\RuleSets;

defined('ABSPATH') || exit;

use Nucleo\Quality\Rules\AnchorDuplicate;
use Nucleo\Quality\Rules\ButtonInert;
use Nucleo\Quality\Rules\ContrastRule;
use Nucleo\Quality\Rules\FormConsequential;
use Nucleo\Quality\Rules\FormRecipient;
use Nucleo\Quality\Rules\H1Missing;
use Nucleo\Quality\Rules\H1Multiple;
use Nucleo\Quality\Rules\HeadingEmpty;
use Nucleo\Quality\Rules\HeadingSkip;
use Nucleo\Quality\Rules\ImageAltMissing;
use Nucleo\Quality\Rules\LandmarkMissing;
use Nucleo\Quality\Rules\LayoutConflict;
use Nucleo\Quality\Rules\LcpLazy;
use Nucleo\Quality\Rules\LinkEmpty;
use Nucleo\Quality\Rules\PositionFixedZIndex;
use Nucleo\Quality\Rules\VisibilityLoggedIn;

// Restituisce list<\Nucleo\Quality\Rule>.

return [
    new H1Missing(),
    new H1Multiple(),
    new HeadingSkip(),
    new HeadingEmpty(),
    new ImageAltMissing(),
    new ContrastRule(),
    new LinkEmpty(),
    new AnchorDuplicate(),
    new LandmarkMissing(),
    new LcpLazy(),
    new VisibilityLoggedIn(),
    new PositionFixedZIndex(),
    new ButtonInert(),
    new FormConsequential(),
    new FormRecipient(),
    new LayoutConflict(),
];
