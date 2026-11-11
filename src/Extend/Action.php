<?php

declare(strict_types=1);

namespace Nucleo\Extend;

defined('ABSPATH') || exit;

/**
 * Azione scritta dallo sviluppatore in un plugin del sito (livello 4).
 * nc/native-form la usa con { "type": "component", "name": "<vendor>/<nome>" }.
 */
interface Action
{
    public function name(): string;

    public function label(): string;

    /**
     * JSON Schema dell'input (type object).
     * @return array<string,mixed>
     */
    public function inputSchema(): array;

    /** @param array<string,mixed> $input già validato contro inputSchema() */
    public function permitted(array $input, ActionContext $context): bool;

    /** @param array<string,mixed> $input */
    public function run(array $input, ActionContext $context): ActionResult;
}
