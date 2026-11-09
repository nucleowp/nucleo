<?php

declare(strict_types=1);

namespace Nucleo\Runtime\Collection;

use Nucleo\Blocks\DynamicRenderer;
use Nucleo\Runtime\Slots;

/** Slot nc/collection: stato da $_GET, pagina dalla cache, markup completo. */
final class CollectionSlot implements DynamicRenderer
{
    public function renderSlot(array $data): string
    {
        $raw = $data['config'] ?? null;
        $config = is_array($raw) ? CollectionConfig::fromArray($raw) : null;
        if ($config === null) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtri e paginazione pubblici della lista, in sola lettura e validati da CollectionState.
        $state = CollectionState::fromRequest($config, wp_unslash($_GET));
        $doc = Slots::document();
        $template = is_array($data['template'] ?? null) ? $data['template'] : [];
        $page = CollectionCache::page($doc, $config, $state, $template);

        return CollectionView::render($data, $config, $state, $page, $doc);
    }
}
