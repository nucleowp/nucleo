<?php

declare(strict_types=1);

namespace Nucleo\Cli;

defined('ABSPATH') || exit;

use Nucleo\Docs\DocsBuilder;
use WP_CLI;

/** Comando WP-CLI: "nucleo docs [<dir>]" scrive la documentazione pubblica generata dal codice. */
final class DocsCommand
{
    private const DEFAULT_DIR = './nucleo-docs';

    public static function register(): void
    {
        WP_CLI::add_command('nucleo docs', [self::class, 'docs']);
    }

    /**
     * Scrive la documentazione generata dal codice: blocchi.md, abilities.md, llms.txt.
     *
     * ## OPTIONS
     *
     * [<dir>]
     * : Cartella di destinazione.
     *
     * @param list<string> $args
     */
    public static function docs(array $args): void
    {
        $dir = $args[0] ?? self::DEFAULT_DIR;
        DocsBuilder::build($dir);
        WP_CLI::success(sprintf(/* translators: %s: folder */ __('Documentation written to %s.', 'nucleo'), $dir));
    }
}
