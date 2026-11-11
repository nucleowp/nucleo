<?php

declare(strict_types=1);

namespace Nucleo\Runtime;

defined('ABSPATH') || exit;

/**
 * Anteprima del CSS per gli amministratori: ?nucleo_css=original|candidate|critical|published.
 * Serve alla misura e al controllo dell'ottimizzazione nel browser dell'admin.
 */
final class CssPreview
{
    public const PARAM = 'nucleo_css';
    /** published: come il frontend normale (varianti e critical pubblicati), senza CSS completi e con barra admin nascosta. */
    public const MODES = ['original', 'candidate', 'critical', 'published'];
    /** Artefatto dei candidati: [template => ['purged' => [handle => url], 'critical' => ?string, ...]]. */
    public const ARTIFACT = 'css-candidate';

    /** Modalità autorizzata da un amministratore, valida mentre la pagina si genera come per un visitatore. */
    private static ?string $asVisitor = null;

    /** Modalità richiesta, solo per utenti con manage_options; null altrimenti. */
    public static function mode(): ?string
    {
        if (self::$asVisitor !== null) {
            return self::$asVisitor;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- anteprima in sola lettura per gli amministratori.
        if (!current_user_can('manage_options') || !isset($_GET[self::PARAM]) || !is_string($_GET[self::PARAM])) {
            return null;
        }
        // Anteprima in sola lettura: il ruolo è già verificato sopra, il nonce non serve.
        $mode = sanitize_key(wp_unslash($_GET[self::PARAM])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return in_array($mode, self::MODES, true) ? $mode : null;
    }

    /**
     * Con un'anteprima attiva la pagina si genera come la vede un visitatore: la misura deve vedere
     * il DOM pubblico (es. il modulo di accesso nell'account, non la bacheca dell'amministratore).
     * Niente barra admin né svuotamento del carrello.
     */
    public static function register(): void
    {
        // Ogni richiesta riparte dal suo utente reale.
        self::$asVisitor = null;
        $mode = self::mode();
        if ($mode === null) {
            return;
        }
        wp_set_current_user(0);
        self::$asVisitor = $mode;
        // Un cambio di utente successivo (es. un altro test, un login) chiude l'anteprima.
        add_action('set_current_user', [self::class, 'close']);
        // add_filter ignora le registrazioni ripetute dello stesso callable: la chiamata è idempotente.
        add_filter('show_admin_bar', [self::class, 'off']);
        add_filter('woocommerce_checkout_redirect_empty_cart', [self::class, 'off']);
    }

    public static function close(): void
    {
        self::$asVisitor = null;
    }

    public static function off(): bool
    {
        return false;
    }
}
