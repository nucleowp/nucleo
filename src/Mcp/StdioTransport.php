<?php

declare(strict_types=1);

namespace Nucleo\Mcp;

/** Trasporto STDIO di MCP: una richiesta JSON-RPC per riga in ingresso, una risposta per riga in uscita. */
final class StdioTransport
{
    /**
     * Legge finché c'è input (EOF compreso). Le righe vuote vengono ignorate.
     *
     * @param resource $in
     * @param resource $out
     */
    public static function run($in, $out): void
    {
        $server = new Server();

        while (($line = fgets($in)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $response = $server->handleJson($line);
            if ($response === null) {
                continue;
            }

            $encoded = wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
                ?: '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Response cannot be encoded."}}';
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- scrive su uno stream (STDOUT), non su un file.
            fwrite($out, $encoded . "\n");
            fflush($out);
        }
    }
}
