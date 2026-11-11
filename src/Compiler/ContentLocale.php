<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

defined('ABSPATH') || exit;

/**
 * Lingua dell'HTML salvato. Il compilatore scrive nell'HTML etichette tradotte (slider, video, menu).
 * Il salvataggio avviene nella lingua di chi salva: un utente in italiano su un sito in inglese
 * lascerebbe etichette italiane nella pagina pubblica. La compilazione usa quindi la lingua del sito,
 * o quella del post se un plugin multilingua la fornisce tramite il filtro nucleo/content_locale.
 */
final class ContentLocale
{
    /** Lingua in cui compilare il post indicato (0: nessun post, ad esempio l'anteprima di un documento nuovo). */
    public static function forPost(int $postId): string
    {
        $locale = apply_filters('nucleo/content_locale', get_locale(), $postId);

        return is_string($locale) && $locale !== '' ? $locale : get_locale();
    }

    /**
     * Esegue $run nella lingua del post: locale di gettext e, con un plugin multilingua, lingua dei contenuti
     * (permalink, menu, sezioni, home_url). La lingua dei contenuti arriva dal filtro nucleo/language_scope.
     *
     * @template T
     * @param callable(): T $run
     * @return T
     */
    public static function runForPost(int $postId, callable $run): mixed
    {
        $scope = $postId > 0 ? apply_filters('nucleo/language_scope', null, $postId) : null;
        if (!$scope instanceof LanguageScope) {
            return self::run(self::forPost($postId), $run);
        }
        $scope->enter();
        try {
            return self::run(self::forPost($postId), $run);
        } finally {
            $scope->leave();
        }
    }

    /**
     * Esegue $run nella lingua indicata e ripristina quella della richiesta.
     * Se la lingua non è installata, $run gira nella lingua corrente.
     *
     * @template T
     * @param callable(): T $run
     * @return T
     */
    public static function run(string $locale, callable $run): mixed
    {
        // switch_to_locale() restituisce false per la lingua già attiva.
        if ($locale === determine_locale() || !switch_to_locale($locale)) {
            return $run();
        }
        try {
            return $run();
        } finally {
            restore_previous_locale();
        }
    }
}
