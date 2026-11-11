<?php

declare(strict_types=1);

namespace Nucleo\Forms;

defined('ABSPATH') || exit;

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
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- esito dell'invio da mostrare dopo il redirect; l'invio stesso è verificato altrove.
        $form = isset($_GET['nc_form']) && is_string($_GET['nc_form']) ? sanitize_key(wp_unslash($_GET['nc_form'])) : '';
        if ($node === '' || $form !== $node) {
            return ['status' => '', 'ticket' => ''];
        }
        $status = isset($_GET['nc_status']) && is_string($_GET['nc_status']) ? sanitize_key(wp_unslash($_GET['nc_status'])) : '';
        if (!in_array($status, self::STATUSES, true)) {
            return ['status' => '', 'ticket' => ''];
        }
        $ticket = $status === 'otp' && isset($_GET['nc_ticket']) && is_string($_GET['nc_ticket'])
            ? sanitize_text_field(wp_unslash($_GET['nc_ticket']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return ['status' => $status, 'ticket' => $ticket];
    }
}
