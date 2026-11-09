<?php

declare(strict_types=1);

namespace Nucleo\Forms;

use Nucleo\Blocks\DynamicRenderer;
use Nucleo\Runtime\Slots;

/** Slot nc/native-form: nonce fresco, contesto e messaggio di esito a ogni richiesta. */
final class NativeFormSlot implements DynamicRenderer
{
    private const STATUSES = ['done', 'error', 'otp'];

    /** @param array<string,mixed> $data */
    public function renderSlot(array $data): string
    {
        $node = is_string($data['node'] ?? null) ? $data['node'] : '';
        $raw = $data['config'] ?? null;
        $config = is_array($raw) ? FormConfig::fromArray($raw) : null;
        if ($config === null) {
            return '';
        }
        $contextId = is_singular() ? (int) get_queried_object_id() : 0;

        return FormView::render(
            $data,
            $config,
            Slots::document(),
            $contextId,
            self::result($node),
        );
    }

    /**
     * Esito dell'invio senza script, letto da $_GET solo se il parametro nc_form è questo modulo.
     * @return array{status:string, ticket:string}
     */
    private static function result(string $node): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- esito dell'invio da mostrare dopo il redirect; l'invio stesso è verificato altrove.
        $query = wp_unslash($_GET);
        $form = is_array($query) && is_string($query['nc_form'] ?? null) ? sanitize_key($query['nc_form']) : '';
        if ($node === '' || $form !== $node) {
            return ['status' => '', 'ticket' => ''];
        }
        $status = is_string($query['nc_status'] ?? null) ? sanitize_key($query['nc_status']) : '';
        if (!in_array($status, self::STATUSES, true)) {
            return ['status' => '', 'ticket' => ''];
        }
        $ticket = $status === 'otp' && is_string($query['nc_ticket'] ?? null)
            ? sanitize_text_field($query['nc_ticket'])
            : '';

        return ['status' => $status, 'ticket' => $ticket];
    }
}
