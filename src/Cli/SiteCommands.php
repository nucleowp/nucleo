<?php

declare(strict_types=1);

namespace Nucleo\Cli;

defined('ABSPATH') || exit;

use Nucleo\Site\SiteExporter;
use Nucleo\Site\SiteFolder;
use Nucleo\Site\SiteImporter;
use WP_CLI;

/** Comandi WP-CLI: "nucleo export <dir>", "nucleo import <dir> [--dry-run] [--force]", "nucleo sync <dir> [--prune]". */
final class SiteCommands
{
    public static function register(): void
    {
        WP_CLI::add_command('nucleo export', [self::class, 'export']);
        WP_CLI::add_command('nucleo import', [self::class, 'import']);
        WP_CLI::add_command('nucleo sync', [self::class, 'sync']);
    }

    /**
     * Esporta pagine, layout, sezioni, token e regole in una cartella JSON.
     *
     * ## OPTIONS
     *
     * [<dir>]
     * : Cartella di destinazione.
     *
     * @param list<string> $args
     */
    public static function export(array $args): void
    {
        self::requireAdmin();
        $dir = self::dir($args);
        SiteFolder::write($dir, SiteExporter::files());
        WP_CLI::success(sprintf(/* translators: %s: export folder */ __('Site exported to %s.', 'nucleo'), $dir));
    }

    /**
     * Importa la cartella JSON: crea o aggiorna i documenti per slug.
     *
     * ## OPTIONS
     *
     * [<dir>]
     * : Cartella da importare.
     *
     * [--dry-run]
     * : Mostra cosa cambierebbe senza salvare.
     *
     * [--force]
     * : Sovrascrive anche i documenti modificati sul sito dopo l'export.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public static function import(array $args, array $assoc): void
    {
        self::requireAdmin();
        $dir = self::dir($args);
        self::report(SiteImporter::import(SiteFolder::read($dir), isset($assoc['dry-run']), isset($assoc['force'])));
    }

    /**
     * Importa la cartella; con --prune cestina i documenti Nucleo che non ci sono più.
     *
     * ## OPTIONS
     *
     * [<dir>]
     * : Cartella da importare.
     *
     * [--prune]
     * : Cestina i documenti Nucleo che non sono più nella cartella.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public static function sync(array $args, array $assoc): void
    {
        self::requireAdmin();
        $dir = self::dir($args);
        $files = SiteFolder::read($dir);
        self::report(SiteImporter::import($files));

        if (isset($assoc['prune'])) {
            WP_CLI::line(sprintf(/* translators: %d: number of posts moved to the trash */ __('Trashed: %d.', 'nucleo'), self::prune($files)));
        }
    }

    /**
     * @param array<string,string> $files
     * @return int numero di documenti cestinati
     */
    private static function prune(array $files): int
    {
        $posts = get_posts([
            'post_type' => array_keys(SiteExporter::FOLDERS),
            'post_status' => 'any',
            'numberposts' => -1,
            'meta_key' => '_nucleo_doc',
        ]);

        $trashed = 0;
        foreach ($posts as $post) {
            if (!isset($files[SiteExporter::pathOf($post)]) && wp_trash_post($post->ID) !== false) {
                $trashed++;
            }
        }

        return $trashed;
    }

    /** @param array{created:int,updated:int,unchanged:int,errors:list<array{file:string,message:string,code:string}>,ids:array<string,int>} $report */
    private static function report(array $report): void
    {
        WP_CLI::line(sprintf(/* translators: 1: created items, 2: updated items, 3: unchanged items */ __('Created: %1$d, updated: %2$d, unchanged: %3$d.', 'nucleo'), $report['created'], $report['updated'], $report['unchanged']));
        foreach ($report['errors'] as $error) {
            WP_CLI::warning("{$error['file']}: {$error['message']}");
        }
    }

    /** Export, import e sync scrivono nel sito: serve un utente con manage_options. */
    private static function requireAdmin(): void
    {
        if (!current_user_can('manage_options')) {
            WP_CLI::error(__('This command needs a user with manage_options. Pass --user=<admin>.', 'nucleo'));
        }
    }

    /** @param list<string> $args */
    private static function dir(array $args): string
    {
        $dir = $args[0] ?? '';
        if ($dir === '') {
            WP_CLI::error(__('Specify the site folder.', 'nucleo'));
        }

        return $dir;
    }
}
