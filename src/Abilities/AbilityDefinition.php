<?php

declare(strict_types=1);

namespace Nucleo\Abilities;

defined('ABSPATH') || exit;

/** One nucleo/* ability. AbilityRegistrar passes args() to wp_register_ability(name(), ...). */
interface AbilityDefinition
{
    /** e.g. "nucleo/doc-get" */
    public function name(): string;

    /**
     * label, description, input_schema, output_schema, meta (annotations, show_in_rest true).
     * execute_callback / permission_callback / category are added by the registrar.
     * @return array<string,mixed>
     */
    public function args(): array;

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error;

    /**
     * WordPress capability check for the current user. $input lets per-post checks run.
     * @param array<string,mixed> $input
     */
    public function permitted(array $input): bool;
}
