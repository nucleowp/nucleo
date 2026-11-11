<?php

declare(strict_types=1);

namespace Nucleo\Runtime;

defined('ABSPATH') || exit;

use Nucleo\Bindings\BindingResolver;
use Nucleo\Bindings\BindSlot;
use Nucleo\Blocks\DynamicRenderer;

/**
 * Slot nc/visibility: restituisce l'HTML del nodo solo se tutte le condizioni valgono per il visitatore.
 * Condizione sconosciuta = falsa; cache e crawler vedono il fallback vuoto.
 */
final class VisibilitySlot implements DynamicRenderer
{
    private const ROLE_PATTERN = '/^role:([a-z0-9_-]+)$/D';

    public function renderSlot(array $data): string
    {
        $html = $data['html'] ?? null;
        $when = $data['when'] ?? null;
        if (!is_string($html) || !is_array($when) || $when === []) {
            return '';
        }

        foreach ($when as $condition) {
            $is = is_array($condition) ? ($condition['is'] ?? null) : null;
            if (!is_string($is) || !self::holds($is, is_array($condition) ? $condition : [])) {
                return '';
            }
        }

        return $html;
    }

    /** @param array<string,mixed> $condition */
    private static function holds(string $is, array $condition): bool
    {
        if ($is === 'logged_in') {
            return is_user_logged_in();
        }
        if ($is === 'logged_out') {
            return !is_user_logged_in();
        }
        if ($is === 'filled' || $is === 'empty') {
            if (!is_string($condition['source'] ?? null) || !is_string($condition['field'] ?? null)) {
                return false;
            }

            return $is === 'filled' ? self::filled($condition) : !self::filled($condition);
        }
        if (preg_match(self::ROLE_PATTERN, $is, $m) === 1) {
            return in_array($m[1], wp_get_current_user()->roles, true);
        }

        return false;
    }

    /** @param array<string,mixed> $condition */
    private static function filled(array $condition): bool
    {
        $source = (string) $condition['source'];
        $field = (string) $condition['field'];
        if (BindSlot::read($source, $field) !== '') {
            return true;
        }
        if ($source !== 'nucleo') {
            return false;
        }

        // Ripetitore: pieno se ha almeno una riga. Campo non ripetitore (null) = vuoto.
        $postId = Loop::post()->ID ?? get_queried_object_id();

        return (BindingResolver::rowCount($postId, $field) ?? 0) > 0;
    }
}
