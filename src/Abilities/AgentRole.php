<?php

declare(strict_types=1);

namespace Nucleo\Abilities;

defined('ABSPATH') || exit;

/** Ruolo per gli utenti dedicati agli agenti AI: modifica documenti e media, non pubblica e non tocca le opzioni. */
final class AgentRole
{
    public const SLUG = 'nucleo_agent';

    public static function install(): void
    {
        if (get_role(self::SLUG) !== null) {
            return;
        }

        add_role(self::SLUG, __('Nucleo Agent', 'nucleo'), [
            'read' => true,
            'edit_posts' => true,
            'edit_pages' => true,
            'edit_published_pages' => true,
            'upload_files' => true,
        ]);
    }
}
