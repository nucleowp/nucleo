<?php

declare(strict_types=1);

namespace Nucleo\Docs;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Manifest;
use Nucleo\OAuth\Scopes;
use Nucleo\Services;
use RuntimeException;

/** Genera la documentazione pubblica (blocchi, ability, llms.txt) dal codice, senza testi scritti a mano. */
final class DocsBuilder
{
    private const ABILITY_PREFIX = 'nucleo/';

    /** Scrive blocchi.md, abilities.md e llms.txt in $dir, creando la cartella se manca. */
    public static function build(string $dir): void
    {
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new RuntimeException("Directory cannot be created: $dir");
        }

        self::write($dir . '/blocchi.md', self::blocks());
        self::write($dir . '/abilities.md', self::abilities());
        self::write($dir . '/llms.txt', self::llms());
    }

    private static function blocks(): string
    {
        $md = "# Nucleo blocks\n\nGenerated from the block manifests. Do not edit by hand.\n";
        foreach (Services::registry()->all() as $name => $manifest) {
            $md .= self::block($name, $manifest);
        }

        return $md;
    }

    private static function block(string $name, Manifest $manifest): string
    {
        $descrizione = $manifest->description !== '' ? $manifest->description : 'No description.';
        $style = $manifest->supports['style'] ?? [];
        $stile = is_array($style) && $style !== [] ? implode(', ', array_map(self::cell(...), $style)) : 'none';
        $script = $manifest->assets['script'] ?? null;
        $strategia = $manifest->assets['strategy'] ?? 'eager';

        $md = "\n## `$name`: {$manifest->title}\n\n";
        $md .= "$descrizione\n\n";
        $md .= '- Category: `' . self::cell($manifest->category) . "`\n";
        $md .= '- Accepts children: ' . self::children($manifest->children) . "\n";
        $md .= '- Parents: ' . self::parents($manifest->parents) . "\n";
        $md .= '- Supported style: ' . $stile . "\n";
        $md .= '- JS script: ' . (is_string($script) ? '`' . $script . '`' : 'none') . "\n";
        $md .= '- Loading strategy: `' . self::cell($strategia) . "`\n\n";
        $md .= self::fieldTable('Content', $manifest->content);
        $md .= self::fieldTable('Settings', $manifest->settings);

        return $md;
    }

    /** @param bool|list<string> $children */
    private static function children(bool|array $children): string
    {
        if ($children === true) {
            return 'yes, any block';
        }
        if ($children === false) {
            return 'no';
        }

        return implode(', ', array_map(static fn (string $nome): string => '`' . $nome . '`', $children));
    }

    /** @param list<string>|null $parents */
    private static function parents(?array $parents): string
    {
        if ($parents === null) {
            return 'any';
        }

        return implode(', ', array_map(static fn (string $nome): string => '`' . $nome . '`', $parents));
    }

    /** @param array<string,mixed> $fields */
    private static function fieldTable(string $titolo, array $fields): string
    {
        $md = "### $titolo\n\n";
        if ($fields === []) {
            return $md . "No fields.\n\n";
        }

        $md .= "| Name | Type | Allowed values | Default | Required | Translatable |\n";
        $md .= "|---|---|---|---|---|---|\n";
        foreach ($fields as $nome => $schema) {
            $schema = is_array($schema) ? $schema : [];
            $enum = $schema['enum'] ?? null;
            $valori = is_array($enum)
                ? implode(', ', array_map(static fn (mixed $v): string => '`' . self::cell($v) . '`', $enum))
                : '—';
            $default = array_key_exists('default', $schema) ? self::cell($schema['default']) : '—';

            $md .= '| `' . $nome . '` | '
                . self::cell($schema['type'] ?? '') . ' | '
                . $valori . ' | '
                . $default . ' | '
                . self::cell($schema['required'] ?? false) . ' | '
                . self::cell($schema['translatable'] ?? false) . " |\n";
        }

        return $md . "\n";
    }

    private static function abilities(): string
    {
        $abilities = wp_get_abilities();
        ksort($abilities);

        $md = "# Nucleo abilities\n\nGenerated from the registered abilities. Do not edit by hand.\n";
        foreach ($abilities as $name => $ability) {
            if (!str_starts_with($name, self::ABILITY_PREFIX)) {
                continue;
            }
            $md .= self::ability($name, $ability);
        }

        return $md;
    }

    private static function ability(string $name, \WP_Ability $ability): string
    {
        $slug = substr($name, strlen(self::ABILITY_PREFIX));
        $scope = Scopes::forAbility($slug) ?? 'none';

        $meta = $ability->get_meta();
        $annotations = $meta['annotations'] ?? [];
        $readonly = is_array($annotations) && ($annotations['readonly'] ?? false) === true;

        $schema = wp_json_encode(
            $ability->get_input_schema(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $md = "\n## `$name`\n\n";
        $md .= $ability->get_description() . "\n\n";
        $md .= '- OAuth scope: `' . $scope . "`\n";
        $md .= '- Read-only: ' . ($readonly ? 'yes' : 'no') . "\n\n";
        $md .= "Input schema:\n\n```json\n$schema\n```\n";

        return $md;
    }

    private static function llms(): string
    {
        $mcp = rest_url('nucleo/v1/mcp');

        return implode("\n", [
            '# Nucleo',
            '',
            '> Nucleo builds WordPress pages and layouts from JSON documents made of blocks. AI Agents write the JSON; Nucleo compiles HTML, CSS and assets on save.',
            '',
            '## How to connect',
            '',
            '- MCP HTTP: ' . $mcp . '. OAuth 2.1 authentication with PKCE S256. The resource metadata is at /.well-known/oauth-protected-resource.',
            '- MCP STDIO: run `wp nucleo mcp` on the site server, for clients that do not support HTTP.',
            '',
            '## Usage rules',
            '',
            '- The AI writes JSON, not HTML. It builds pages with blocks.',
            '- Every write returns the quality report.',
            '- Use `dryRun: true` to get the diff and report without saving.',
            '',
            '## Documentation',
            '',
            '- [Blocks](blocchi.md): fields, allowed values and supports of each block.',
            '- [Abilities](abilities.md): name, OAuth scope and input schema of each ability.',
            '',
        ]);
    }

    /** Celle di tabella: le pipe e gli a capo romperebbero la riga markdown. */
    private static function cell(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        $text = is_string($value) ? $value : (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return str_replace(['|', "\n"], ['\|', ' '], $text);
    }

    private static function write(string $file, string $content): void
    {
        if (file_put_contents($file, $content) === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new RuntimeException("Write failed: $file");
        }
    }
}
