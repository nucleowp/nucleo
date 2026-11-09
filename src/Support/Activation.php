<?php

declare(strict_types=1);

namespace Nucleo\Support;

/** Runs on plugin activation: tables, roles, first artifacts. Each step is skipped if its module is missing. */
final class Activation
{
    /** @return list<string> */
    private static function steps(): array
    {
        return [
        // Prima degli altri passi: TokensPublisher e RulesPublisher scrivono nello store.
        'Nucleo\\Storage\\StorePaths',
        'Nucleo\\OAuth\\Server',
        'Nucleo\\Storage\\AuditLog',
        'Nucleo\\Abilities\\AgentRole',
        'Nucleo\\Compiler\\TokensPublisher',
        'Nucleo\\Assets\\RulesPublisher',
        'Nucleo\\Support\\LegacyTransients',
        ];
    }

    /** Versione del plugin per cui sono state create tabelle e opzioni. */
    public const VERSION_OPTION = 'nucleo_installed_version';

    public static function activate(): void
    {
        foreach (self::steps() as $class) {
            if (class_exists($class) && method_exists($class, 'install')) {
                $class::install();
            }
        }
        update_option(self::VERSION_OPTION, NUCLEO_VERSION);
    }

    /**
     * Dopo un aggiornamento WordPress non chiama l'hook di attivazione:
     * se la versione installata è diversa, ripete i passi di installazione (dbDelta è idempotente).
     */
    public static function maybeUpgrade(): void
    {
        $installed = get_option(self::VERSION_OPTION);
        if ($installed !== NUCLEO_VERSION) {
            self::activate();
            // Renderer e CSS dei blocchi possono essere cambiati: le pagine si ricompilano in coda (cron).
            if (is_string($installed) && $installed !== '') {
                \Nucleo\Compiler\RebuildQueue::enqueueAll();
            }
        }
    }
}
