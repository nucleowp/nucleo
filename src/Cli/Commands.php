<?php

declare(strict_types=1);

namespace Nucleo\Cli;

defined('ABSPATH') || exit;

use Nucleo\Mcp\StdioTransport;

/**
 * Comandi WP-CLI: `wp nucleo mcp` (server MCP su STDIO), `wp nucleo status`,
 * `wp nucleo ability list` e `wp nucleo ability run <nome> [--input=<json>]`.
 */
final class Commands
{
    public static function register(): void
    {
        if (!class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('nucleo mcp', [self::class, 'mcp']);
        \WP_CLI::add_command('nucleo status', [self::class, 'status']);
        \WP_CLI::add_command('nucleo ability list', [self::class, 'abilityList']);
        \WP_CLI::add_command('nucleo ability run', [self::class, 'abilityRun']);
        AbilityCommands::register();
    }

    /**
     * Avvia il server MCP su STDIO con i permessi dell'utente indicato con --user=<id|login>.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public static function mcp(array $args, array $assoc): void
    {
        if (get_current_user_id() === 0) {
            self::fail(__('Pass the user with --user=<id|login>.', 'nucleo'));
        }

        // STDOUT porta solo JSON-RPC: errori del database e output di terzi si scartano.
        global $wpdb;
        $wpdb->hide_errors();
        ob_start(static fn (): string => '');
        StdioTransport::run(STDIN, STDOUT);
        ob_end_clean();
    }

    /**
     * Stampa l'output dell'ability nucleo/status.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public static function status(array $args, array $assoc): void
    {
        $ability = wp_get_ability('nucleo/status');
        $result = $ability === null
            ? new \WP_Error('nucleo_status_missing', __('Ability nucleo/status is not registered.', 'nucleo'))
            : $ability->execute([]);
        if ($result instanceof \WP_Error) {
            self::fail($result->get_error_code() . ': ' . $result->get_error_message());
        }

        \WP_CLI::line((string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Elenca le ability nucleo/* con descrizione.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public static function abilityList(array $args, array $assoc): void
    {
        foreach (wp_get_abilities() as $ability) {
            if (str_starts_with($ability->get_name(), 'nucleo/')) {
                \WP_CLI::line($ability->get_name() . '  ' . $ability->get_description());
            }
        }
    }

    /**
     * Esegue un'ability: `wp nucleo ability run doc-get --input='{"postId":12}' --user=admin`.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public static function abilityRun(array $args, array $assoc): void
    {
        $input = is_string($assoc['input'] ?? null) ? $assoc['input'] : '{}';
        $result = self::runAbility($args[0] ?? '', $input);
        if ($result instanceof \WP_Error) {
            self::fail($result->get_error_code() . ': ' . $result->get_error_message());
        }
        \WP_CLI::line((string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Separata dall'output per i test. Il prefisso nucleo/ è facoltativo. */
    public static function runAbility(string $name, string $json): mixed
    {
        $name = str_starts_with($name, 'nucleo/') ? $name : 'nucleo/' . $name;
        $ability = wp_get_ability($name);
        if ($ability === null) {
            return new \WP_Error('unknown_ability', sprintf(/* translators: %s: ability name */ __('Unknown ability: %s.', 'nucleo'), $name));
        }
        $input = json_decode($json, true);
        if (!is_array($input)) {
            return new \WP_Error('invalid_json', __('--input must be a JSON object.', 'nucleo'));
        }

        return $ability->execute($input);
    }

    private static function fail(string $message): void
    {
        \WP_CLI::error($message);
    }
}
