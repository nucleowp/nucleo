<?php

declare(strict_types=1);

namespace Nucleo\Guide;

/**
 * Collega segnalazioni ed errori alle regole della guida a fasi (docs/16-guida-a-fasi.md).
 * Le risposte espongono il codice nel campo "guide": "rule" resta l'id della regola di qualità.
 */
final class RuleCodes
{
    /** Id della regola di qualità → codice della guida. */
    private const QUALITY = [
        'style.token.literal' => 'R1.2',
        'content.section.missing' => 'R1.5',
        'content.link.path' => 'R2.3',
        'content.link.missing' => 'R2.3',
        'content.button.inert' => 'R4.5',
        'form.action.consequential' => 'R4.4',
    ];

    /** Codice stabile di un errore (validazione, import, ability) → codice della guida. */
    private const ERRORS = [
        'card_missing' => 'R3.3',
        'card_block_not_allowed' => 'R3.3',
        'component_code' => 'R4.3',
    ];

    public static function forQuality(string $rule): ?string
    {
        return self::QUALITY[$rule] ?? null;
    }

    public static function forError(string $code): ?string
    {
        return self::ERRORS[$code] ?? null;
    }
}
