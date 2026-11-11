<?php

declare(strict_types=1);

namespace Nucleo\Cli;

defined('ABSPATH') || exit;

/**
 * Un comando WP-CLI per ogni ability nucleo/*: "nucleo/doc-patch" diventa "wp nucleo doc patch".
 * Esegue e stampa come "ability run". Non sovrascrive i comandi esistenti.
 */
final class AbilityCommands
{
    /** Comandi e gruppi già occupati da Commands, SiteCommands e DocsCommand. */
    private const RESERVED = ['status', 'export', 'import', 'sync', 'docs', 'mcp', 'ability'];

    private const QUALITY_SCAN = 'nucleo/quality-scan';

    public static function register(): void
    {
        if (!class_exists('WP_CLI')) {
            return;
        }

        // wp_abilities_api_init si attiva in modo pigro: su init, prima della esecuzione del comando, wp_get_abilities() lo avvia.
        add_action('init', [self::class, 'addCommands'], 99);
    }

    /** Registra un comando per ogni ability nucleo/* presente, saltando i nomi occupati. */
    public static function addCommands(): void
    {
        if (!function_exists('wp_get_abilities')) {
            return;
        }

        foreach (wp_get_abilities() as $ability) {
            $name = $ability->get_name();
            $command = self::commandName($name);
            if ($command === null || self::isReserved($command)) {
                continue;
            }

            $handler = $name === self::QUALITY_SCAN
                ? [self::class, 'qualityScan']
                : static function (array $args, array $assoc) use ($name): void {
                    Commands::abilityRun([$name], $assoc);
                };
            \WP_CLI::add_command('nucleo ' . $command, $handler);
        }
    }

    /**
     * Scansione qualità: senza --all come "ability run"; con --all percorre tutti i lotti e stampa il risultato unito.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public static function qualityScan(array $args, array $assoc): void
    {
        if (!isset($assoc['all'])) {
            Commands::abilityRun([self::QUALITY_SCAN], $assoc);

            return;
        }

        $merged = ['site' => [], 'pages' => [], 'next' => null];
        $page = 1;
        do {
            $result = Commands::runAbility(self::QUALITY_SCAN, (string) wp_json_encode(['page' => $page]));
            if ($result instanceof \WP_Error) {
                \WP_CLI::error($result->get_error_code() . ': ' . $result->get_error_message());
            }
            // Le regole di sito leggono tutto il sito a ogni lotto: basta il primo.
            if ($page === 1) {
                $merged['site'] = $result['site'] ?? [];
            }
            $merged['pages'] = array_merge($merged['pages'], $result['pages'] ?? []);
            $page = $result['next'] ?? null;
        } while ($page !== null);

        \WP_CLI::line((string) wp_json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Nome del comando dopo "nucleo": "nucleo/doc-patch" → "doc patch", "nucleo/css-optimize-apply" → "css optimize-apply",
     * "nucleo/status" → "status". Null se il nome non è nucleo/* o ha parti vuote.
     */
    public static function commandName(string $ability): ?string
    {
        if (!str_starts_with($ability, 'nucleo/')) {
            return null;
        }

        $parts = explode('-', substr($ability, strlen('nucleo/')), 2);
        if (in_array('', $parts, true)) {
            return null;
        }

        return implode(' ', $parts);
    }

    /** Vero se il comando è occupato o cade sotto un gruppo esistente (per esempio "ability run"). */
    public static function isReserved(string $command): bool
    {
        return in_array(explode(' ', $command, 2)[0], self::RESERVED, true);
    }
}
