<?php

declare(strict_types=1);

namespace Nucleo\Quality;

defined('ABSPATH') || exit;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
