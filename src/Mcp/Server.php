<?php

declare(strict_types=1);

namespace Nucleo\Mcp;

defined('ABSPATH') || exit;

use Nucleo\OAuth\CurrentToken;
use Nucleo\OAuth\Scopes;

/**
 * Server MCP minimale (JSON-RPC 2.0) che espone le ability nucleo/* come tool.
 * Permessi e schemi restano nelle ability: qui si filtra in tools/list per scope OAuth.
 */
final class Server
{
    public const PROTOCOL_VERSION = '2025-06-18';

    private const ABILITY_PREFIX = 'nucleo/';

    private const INSTRUCTIONS = 'Before writing, call nucleo-guide and follow the phases in order. It reads the state of the site and returns the rules, the tools and the exit conditions of the current phase. nucleo-site-describe describes the site, the design tokens and the document constraints. Pages, posts, layouts and sections built with Nucleo blocks go through nucleo-doc-*; title, fields, terms and items without a Nucleo document go through nucleo-content-*. Everything goes through Nucleo: no mu-plugins, no code in functions.php or in the theme, no CSS or scripts in wp_head. When Nucleo cannot do something, tell the user it is a Nucleo gap; site PHP goes only in a component (nucleo component new), with the user\'s consent.';

    /**
     * Decodifica un messaggio JSON grezzo (una riga STDIO o il corpo HTTP) e risponde.
     * @return array<string,mixed>|null
     */
    public function handleJson(string $raw): ?array
    {
        try {
            $msg = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error(null, -32700, __('Parse error: invalid JSON.', 'nucleo'));
        }

        if (!is_array($msg)) {
            return $this->error(null, -32600, __('Invalid request: expected a JSON object.', 'nucleo'));
        }

        return $this->handle($msg);
    }

    /**
     * @param array<mixed> $msg
     * @return array<string,mixed>|null null per le notifiche (senza id)
     */
    public function handle(array $msg): ?array
    {
        if (array_is_list($msg)) {
            return $this->error(null, -32600, __('JSON-RPC batch is not supported.', 'nucleo'));
        }

        $id = $msg['id'] ?? null;
        if (!is_int($id) && !is_string($id)) {
            $id = null;
        }

        if (($msg['jsonrpc'] ?? null) !== '2.0' || !is_string($msg['method'] ?? null)) {
            return $this->error($id, -32600, __('Invalid request: jsonrpc 2.0 and method are required.', 'nucleo'));
        }

        if (!array_key_exists('id', $msg)) {
            return null;
        }

        $method = $msg['method'];
        $params = $msg['params'] ?? [];

        return match ($method) {
            'initialize' => $this->result($id, $this->initialize()),
            'ping' => $this->result($id, new \stdClass()),
            'tools/list' => $this->result($id, $this->listTools()),
            'tools/call' => $this->callTool($id, $params),
            default => $this->error($id, -32601, sprintf(/* translators: %s: JSON-RPC method name. */ __('Method not supported: %s.', 'nucleo'), $method)),
        };
    }

    /** @return array<string,mixed> */
    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'nucleo', 'version' => NUCLEO_VERSION],
            'instructions' => self::INSTRUCTIONS,
        ];
    }

    /** @return array{tools: list<array<string,mixed>>} */
    private function listTools(): array
    {
        $scopes = CurrentToken::scopes();
        $tools = [];

        foreach ($this->abilities() as $ability) {
            $name = $ability->get_name();
            if ($scopes !== null && !in_array(Scopes::forAbility($this->shortName($name)), $scopes, true)) {
                continue;
            }

            $tool = [
                'name' => self::toolName($name),
                'description' => $ability->get_description(),
                'inputSchema' => $ability->get_input_schema() ?: ['type' => 'object'],
            ];
            $annotations = $this->annotations($ability->get_meta());
            if ($annotations !== []) {
                $tool['annotations'] = $annotations;
            }
            $tools[] = $tool;
        }

        return ['tools' => $tools];
    }

    /** @return array<string,mixed> */
    private function callTool(mixed $id, mixed $params): array
    {
        $name = is_array($params) ? ($params['name'] ?? null) : null;
        $arguments = is_array($params) ? ($params['arguments'] ?? []) : [];
        if (!is_string($name) || !is_array($arguments)) {
            return $this->error($id, -32602, __('Invalid params: name must be a string and arguments an object.', 'nucleo'));
        }

        $ability = $this->findTool($name);
        if ($ability === null) {
            return $this->error($id, -32602, sprintf(/* translators: %s: tool name. */ __('Unknown tool: %s.', 'nucleo'), $name));
        }

        // Scope first: WordPress validates the input before permissions, and the agent must learn the real cause.
        $scopes = CurrentToken::scopes();
        $needed = Scopes::forAbility($this->shortName($ability->get_name()));
        if ($scopes !== null && ($needed === null || !in_array($needed, $scopes, true))) {
            // Stesso formato dell'errore di permission_callback: code, message e data.scope per l'autorizzazione incrementale.
            $error = ['ok' => false, 'error' => [
                'code' => 'insufficient_scope',
                /* translators: %s: name of the OAuth scope required by the ability. */
                'message' => sprintf(__('Required scope: %s.', 'nucleo'), $needed ?? __('not mapped for this ability', 'nucleo')),
                'data' => ['scope' => $needed],
            ]];

            return $this->result($id, [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => (string) wp_json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
                'structuredContent' => $error,
            ]);
        }

        $outcome = $ability->execute($arguments);
        if ($outcome instanceof \WP_Error) {
            // I dati dell'errore (path, fix, valori ammessi) servono all'agente per correggere la richiesta.
            $error = ['ok' => false, 'error' => ['code' => (string) $outcome->get_error_code(), 'message' => $outcome->get_error_message()]];
            $data = $outcome->get_error_data();
            if (is_array($data)) {
                unset($data['status']);
                if ($data !== []) {
                    $error['error']['data'] = $data;
                }
            }

            return $this->result($id, [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => (string) wp_json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
                'structuredContent' => $error,
            ]);
        }

        $json = (string) wp_json_encode($outcome, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_array($outcome)) {
            return $this->result($id, ['content' => [['type' => 'text', 'text' => $json]]]);
        }

        return $this->result($id, [
            'content' => [['type' => 'text', 'text' => $json]],
            'structuredContent' => $outcome,
            'isError' => self::failed($outcome),
        ]);
    }

    /**
     * Esito di errore per il modello: ok false, oppure un report senza ok con errori (site-import, kit-apply).
     * Il report resta intero in structuredContent: il lavoro già applicato e gli errori da correggere.
     * @param array<mixed> $outcome
     */
    public static function failed(array $outcome): bool
    {
        if (array_key_exists('ok', $outcome)) {
            return $outcome['ok'] === false;
        }

        return is_array($outcome['errors'] ?? null) && $outcome['errors'] !== [];
    }

    /** @return list<\WP_Ability> */
    private function abilities(): array
    {
        $found = [];
        foreach (wp_get_abilities() as $ability) {
            if (str_starts_with($ability->get_name(), self::ABILITY_PREFIX)) {
                $found[] = $ability;
            }
        }

        return $found;
    }

    private function findTool(string $tool): ?\WP_Ability
    {
        foreach ($this->abilities() as $ability) {
            if (self::toolName($ability->get_name()) === $tool) {
                return $ability;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,bool>
     */
    private function annotations(array $meta): array
    {
        $source = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];
        $map = ['readonly' => 'readOnlyHint', 'destructive' => 'destructiveHint', 'idempotent' => 'idempotentHint'];

        $out = [];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $source)) {
                $out[$to] = (bool) $source[$from];
            }
        }

        return $out;
    }

    private static function toolName(string $ability): string
    {
        return str_replace('/', '-', $ability);
    }

    private function shortName(string $ability): string
    {
        return substr($ability, strlen(self::ABILITY_PREFIX));
    }

    /** @return array<string,mixed> */
    private function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string,mixed> */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
