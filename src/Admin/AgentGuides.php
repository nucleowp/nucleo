<?php

declare(strict_types=1);

namespace Nucleo\Admin;

/**
 * Guide di collegamento degli agenti per la pagina Agenti: per ogni client passi, snippet, callback e note.
 * Testi in inglese come nel codice; la fonte è docs/contracts/agent-guides.md.
 * Gli snippet JSON ricevono url e path come valori dell'array, così l'encoder li escapa correttamente.
 */
final class AgentGuides
{
    /**
     * @return list<array{key:string,label:string,cloud:bool,steps:list<string>,snippets:list<array{label:string,code:string}>,callbacks:list<string>,notes:list<string>}>
     */
    public static function all(string $url, string $path): array
    {
        return [
            [
                'key' => 'claude',
                'label' => __('Claude (desktop, web, mobile)', 'nucleo'),
                'cloud' => true,
                'steps' => [
                    __('Open Settings > Connectors in the Claude app or on claude.ai.', 'nucleo'),
                    __('Click "Add custom connector", enter the name Nucleo and the MCP server URL.', 'nucleo'),
                    __('Click Add, then Connect: WordPress opens the authorization page. Sign in and approve.', 'nucleo'),
                    __('Enable the connector in a chat from the tools menu.', 'nucleo'),
                ],
                'snippets' => [
                    ['label' => __('Connector URL', 'nucleo'), 'code' => $url],
                    [
                        'label' => __('Local site: claude_desktop_config.json', 'nucleo'),
                        'code' => self::json(['mcpServers' => ['nucleo' => ['command' => 'npx', 'args' => ['-y', 'mcp-remote', $url]]]]),
                    ],
                ],
                'callbacks' => ['https://claude.ai/api/mcp/auth_callback', 'http://localhost:3334/oauth/callback'],
                'notes' => [
                    __('The configuration file accepts only local servers: a url entry there does not work.', 'nucleo'),
                    __('The local configuration needs Node.js and starts the authorization in the browser. Restart Claude after editing the file.', 'nucleo'),
                ],
            ],
            [
                'key' => 'claude-code',
                'label' => __('Claude Code', 'nucleo'),
                'cloud' => false,
                'steps' => [
                    __('Run the command in a terminal.', 'nucleo'),
                    __('In Claude Code type /mcp, choose nucleo and Authenticate.', 'nucleo'),
                    __('Approve on the WordPress page that opens.', 'nucleo'),
                ],
                'snippets' => [
                    ['label' => __('Command', 'nucleo'), 'code' => 'claude mcp add --transport http --scope user nucleo ' . $url],
                    [
                        'label' => __('With an agent added on this page', 'nucleo'),
                        'code' => 'claude mcp add --transport http --scope user --client-id <client_id> --callback-port 8080 nucleo ' . $url,
                    ],
                ],
                'callbacks' => ['http://localhost:8080/callback'],
                'notes' => [
                    __('--scope user makes the server available in every project.', 'nucleo'),
                ],
            ],
            [
                'key' => 'chatgpt',
                'label' => __('ChatGPT', 'nucleo'),
                'cloud' => true,
                'steps' => [
                    __('Open Settings > Apps & Connectors > Advanced settings and turn on Developer mode.', 'nucleo'),
                    __('In Apps & Connectors click Create.', 'nucleo'),
                    __('Enter the name Nucleo and the MCP server URL, choose OAuth authentication and confirm.', 'nucleo'),
                    __('Approve on the WordPress page that opens.', 'nucleo'),
                ],
                'snippets' => [
                    ['label' => __('Connector URL', 'nucleo'), 'code' => $url],
                ],
                'callbacks' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
                'notes' => [
                    __('Developer mode needs a paid plan; on Business and Enterprise an admin must allow custom connectors.', 'nucleo'),
                    __('ChatGPT asks for confirmation before every write action.', 'nucleo'),
                ],
            ],
            [
                'key' => 'cursor',
                'label' => __('Cursor', 'nucleo'),
                'cloud' => false,
                'steps' => [
                    __('Open Cursor Settings > MCP > Add new MCP server, or edit ~/.cursor/mcp.json.', 'nucleo'),
                    __('Paste the configuration and save.', 'nucleo'),
                    __('Click Connect next to nucleo and approve in the browser.', 'nucleo'),
                ],
                'snippets' => [
                    [
                        'label' => __('~/.cursor/mcp.json', 'nucleo'),
                        'code' => self::json(['mcpServers' => ['nucleo' => ['url' => $url]]]),
                    ],
                ],
                'callbacks' => ['http://localhost:8787/callback'],
                'notes' => [
                    __('With an agent added on this page, add "auth":{"CLIENT_ID":"<client_id>"} to the server entry.', 'nucleo'),
                ],
            ],
            [
                'key' => 'vscode',
                'label' => __('VS Code (GitHub Copilot)', 'nucleo'),
                'cloud' => false,
                'steps' => [
                    __('Run MCP: Open User Configuration from the Command Palette, or create .vscode/mcp.json in the project.', 'nucleo'),
                    __('Paste the configuration and save.', 'nucleo'),
                    __('Click Start above the nucleo entry and approve in the browser.', 'nucleo'),
                    __('In Copilot Chat choose Agent mode: the Nucleo tools are in the tools list.', 'nucleo'),
                ],
                'snippets' => [
                    [
                        'label' => __('mcp.json', 'nucleo'),
                        'code' => self::json(['servers' => ['nucleo' => ['type' => 'http', 'url' => $url]]]),
                    ],
                ],
                'callbacks' => ['http://127.0.0.1:33418', 'https://vscode.dev/redirect'],
                'notes' => [],
            ],
            [
                'key' => 'codex',
                'label' => __('OpenAI Codex', 'nucleo'),
                'cloud' => false,
                'steps' => [
                    __('Run the two commands in a terminal.', 'nucleo'),
                    __('Approve on the WordPress page that opens.', 'nucleo'),
                ],
                'snippets' => [
                    [
                        'label' => __('Commands', 'nucleo'),
                        'code' => implode("\n", ['codex mcp add nucleo --url ' . $url, 'codex mcp login nucleo']),
                    ],
                    [
                        'label' => __('~/.codex/config.toml', 'nucleo'),
                        'code' => implode("\n", ['[mcp_servers.nucleo]', 'url = "' . $url . '"']),
                    ],
                ],
                'callbacks' => [],
                'notes' => [
                    __('The Codex IDE extension reads the same configuration.', 'nucleo'),
                ],
            ],
            [
                'key' => 'gemini',
                'label' => __('Gemini CLI', 'nucleo'),
                'cloud' => false,
                'steps' => [
                    __('Add the configuration to ~/.gemini/settings.json.', 'nucleo'),
                    __('Start gemini and type /mcp auth nucleo.', 'nucleo'),
                    __('Approve on the WordPress page that opens.', 'nucleo'),
                ],
                'snippets' => [
                    [
                        'label' => __('~/.gemini/settings.json', 'nucleo'),
                        'code' => self::json(['mcpServers' => ['nucleo' => ['httpUrl' => $url]]]),
                    ],
                ],
                'callbacks' => ['http://localhost:7777/oauth/callback'],
                'notes' => [
                    __('Use httpUrl, not url: url is for the older SSE transport.', 'nucleo'),
                ],
            ],
            [
                'key' => 'windsurf',
                'label' => __('Windsurf', 'nucleo'),
                'cloud' => false,
                'steps' => [
                    __('In Cascade open the MCP servers panel and choose View raw config, or edit ~/.codeium/windsurf/mcp_config.json.', 'nucleo'),
                    __('Paste the configuration, save and click Refresh.', 'nucleo'),
                    __('Approve on the WordPress page that opens.', 'nucleo'),
                ],
                'snippets' => [
                    [
                        'label' => __('mcp_config.json', 'nucleo'),
                        'code' => self::json(['mcpServers' => ['nucleo' => ['serverUrl' => $url]]]),
                    ],
                ],
                'callbacks' => [],
                'notes' => [],
            ],
            [
                'key' => 'stdio',
                'label' => __('Terminal (STDIO)', 'nucleo'),
                'cloud' => false,
                'steps' => [
                    __('Use it when the agent runs on the same server as WordPress, with WP-CLI.', 'nucleo'),
                    __('No authorization: the agent acts as the WordPress user given with --user.', 'nucleo'),
                ],
                'snippets' => [
                    ['label' => __('Command', 'nucleo'), 'code' => 'wp nucleo mcp --user=<login>'],
                    [
                        'label' => __('MCP client configuration', 'nucleo'),
                        'code' => self::json(['mcpServers' => ['nucleo' => [
                            'command' => 'wp',
                            'args' => ['nucleo', 'mcp', '--user=<login>', '--path=' . $path],
                        ]]]),
                    ],
                ],
                'callbacks' => [],
                'notes' => [
                    __('Choose a user with only the capabilities the agent needs.', 'nucleo'),
                ],
            ],
        ];
    }

    /**
     * JSON pretty-printed con due spazi per livello, senza barre escapate.
     *
     * @param array<mixed> $data
     */
    private static function json(array $data): string
    {
        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '';
        }

        return (string) preg_replace_callback(
            '/^(?: {4})+/m',
            static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 2)),
            $json
        );
    }
}
