<?php

declare(strict_types=1);

namespace Nucleo\Support;

defined('ABSPATH') || exit;

/** Request context that decides which modules load. */
enum Context: string
{
    case Frontend = 'frontend';
    case Admin = 'admin';
    case Rest = 'rest';
    case Cli = 'cli';
    case Cron = 'cron';
}
