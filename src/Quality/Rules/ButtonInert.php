<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

defined('ABSPATH') || exit;

use Nucleo\Blocks\Core\ButtonRenderer;
use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;

/** Pulsante senza link né azione (href vuoto o '#'): suggerisce il blocco interattivo adatto al testo. */
final class ButtonInert implements Rule
{
    /**
     * Gruppi in ordine di precedenza: vince il primo che trova una parola intera del testo.
     * Le radici con \w* coprono le forme italiane (filtri, filtra; domande; iscriviti).
     */
    private const SUGGESTIONS = [
        ['nc/collection', '(?:filtr\\w*|filters?|ordina\\w*|sort|azzera\\w*|reset|clear|carica\\s+altr\\w*|load\\s+more|mostra\\s+altr\\w*|show\\s+more)'],
        ['nc/tabs', '(?:tabs?|schede|scheda)'],
        ['nc/accordion', '(?:faq|domand\\w*|espandi|expand|open)'],
        ['nc/slider', '(?:prev|previous|next|precedent\\w*|successiv\\w*)'],
        ['nc/search', '(?:cerc\\w*|search)'],
        ['nc/native-form', '(?:invia\\w*|send|iscriv\\w*|subscribe|contatta\\w*|contact|prenota\\w*|book)'],
    ];

    /** Testo fatto solo di frecce: comando di un carosello. */
    private const ARROWS = '/^[\\s←→‹›«»<>]+$/u';

    public function id(): string
    {
        return 'content.button.inert';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        foreach ($page->buttons as $button) {
            // Stesso controllo del renderer: un href pericoloso (javascript:) diventa un <button> senza azione.
            $href = ButtonRenderer::safeHref($button['href']);
            if ($href !== null && $href !== '#') {
                continue;
            }

            $block = self::suggestion($button['text']);
            $message = $block === null
                ? sprintf(
                    /* translators: %s: button text. */
                    __('The button "%s" has no link or action: add an href (page:<slug>, home) or use an interactive block (nc/collection, nc/tabs, nc/accordion, nc/slider, nc/native-form, nc/search).', 'nucleo'),
                    $button['text'],
                )
                : sprintf(
                    /* translators: 1: button text, 2: suggested block such as nc/collection. */
                    __('The button "%1$s" has no link or action: use %2$s.', 'nucleo'),
                    $button['text'],
                    $block,
                );

            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                $message,
                [['doc' => $button['doc'], 'id' => $button['id']]],
                [],
                true,
            );
        }

        return $issues;
    }

    /** Blocco suggerito dal testo del pulsante, o null se nessun gruppo corrisponde. */
    private static function suggestion(string $text): ?string
    {
        if (preg_match(self::ARROWS, $text) === 1) {
            return 'nc/slider';
        }
        foreach (self::SUGGESTIONS as [$block, $words]) {
            if (preg_match('/(*UCP)\\b' . $words . '\\b/iu', $text) === 1) {
                return $block;
            }
        }

        return null;
    }
}
