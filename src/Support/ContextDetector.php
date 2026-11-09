<?php

declare(strict_types=1);

namespace Nucleo\Support;

/** Decides the request context from WordPress state. Checks are injectable for tests. */
final class ContextDetector
{
    /** @var callable(): bool */
    private $isCli;

    /** @var callable(): bool */
    private $isCron;

    /** @var callable(): bool */
    private $isAdmin;

    public function __construct(?callable $isCli = null, ?callable $isCron = null, ?callable $isAdmin = null)
    {
        $this->isCli = $isCli ?? static fn (): bool => defined('WP_CLI');
        $this->isCron = $isCron ?? static fn (): bool => function_exists('wp_doing_cron') && wp_doing_cron();
        $this->isAdmin = $isAdmin ?? static fn (): bool => function_exists('is_admin') && is_admin();
    }

    public function detect(): Context
    {
        if (($this->isCli)()) {
            return Context::Cli;
        }
        if (($this->isCron)()) {
            return Context::Cron;
        }
        if (($this->isAdmin)()) {
            return Context::Admin;
        }

        return Context::Frontend;
    }
}
