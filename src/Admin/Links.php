<?php

declare(strict_types=1);

namespace Nucleo\Admin;

defined('ABSPATH') || exit;

/**
 * Fonte unica degli URL del sito di vendita: acquisto, account, documentazione, changelog.
 * Il sito è bilingue: italiano alla radice, inglese sotto /en/. La lingua segue l'utente collegato.
 */
final class Links
{
    public const STORE = 'https://nucleowp.dev';

    /** Pagine della documentazione: chiave => percorso, uguale in italiano e in inglese. */
    private const DOCS = [
        'index' => '',
        'agent' => 'connect-agent/',
        'mcp' => 'mcp-oauth/',
        'tokens' => 'design-tokens/',
        'performance' => 'optimizer-assets/',
        'admin' => 'wordpress-admin/',
    ];

    /** Ancora della sezione prezzi nella pagina Pro: [IT, EN]. */
    private const PRICING = ['prezzi', 'pricing'];

    public static function register(): void
    {
        add_filter('plugin_row_meta', [self::class, 'rowMeta'], 10, 2);
    }

    /** URL della pagina di documentazione; chiave ignota → indice. */
    public static function docs(string $page = 'index', ?string $locale = null): string
    {
        return self::base($locale) . '/docs/' . (self::DOCS[$page] ?? self::DOCS['index']);
    }

    /** Bacheca di feedback e assistenza del sito di vendita, nella lingua dell'utente. */
    public static function feedback(?string $locale = null): string
    {
        return self::base($locale) . '/feedback';
    }

    /** Pagina Pro; con un'ancora porta a una sezione o a un piano (single, agency, unlimited, pricing). */
    public static function pro(?string $anchor = null, ?string $locale = null): string
    {
        if ($anchor === 'pricing') {
            $anchor = self::PRICING[self::italian($locale) ? 0 : 1];
        }

        return self::base($locale) . '/pro/' . ($anchor === null ? '' : '#' . $anchor);
    }

    /** Area clienti: stesso percorso nelle due lingue, la lingua segue il cliente. */
    public static function account(string $path = ''): string
    {
        return self::STORE . '/account' . $path;
    }

    /** Changelog; con una versione aggiunge l'ancora del sito (0.2.0 → #v0-2-0). */
    public static function changelog(?string $version = null, ?string $locale = null): string
    {
        $url = self::base($locale) . '/changelog/';

        return $version === null ? $url : $url . '#v' . str_replace('.', '-', $version);
    }

    /**
     * Link per window.nucleoAdmin.links.
     *
     * @return array<string, string>
     */
    public static function forAdmin(): array
    {
        return [
            'docs' => self::docs(),
            'changelog' => self::changelog(),
            'release' => self::changelog(NUCLEO_VERSION),
            'agent' => self::docs('agent'),
            'mcp' => self::docs('mcp'),
            'feedback' => self::feedback(),
        ];
    }

    /**
     * Documentation e Release notes nella riga del plugin in plugins.php.
     *
     * @param array<int|string, string> $meta
     * @return array<int|string, string>
     */
    public static function rowMeta(array $meta, string $file): array
    {
        if ($file !== plugin_basename(NUCLEO_FILE)) {
            return $meta;
        }
        $meta[] = sprintf('<a href="%s" rel="noopener">%s</a>', esc_url(self::docs()), esc_html__('Documentation', 'nucleo'));
        $meta[] = sprintf('<a href="%s" rel="noopener">%s</a>', esc_url(self::changelog()), esc_html__('Release notes', 'nucleo'));

        return $meta;
    }

    /** Radice del sito nella lingua dell'utente: italiano alla radice, inglese sotto /en. */
    private static function base(?string $locale): string
    {
        return self::STORE . (self::italian($locale) ? '' : '/en');
    }

    private static function italian(?string $locale): bool
    {
        return str_starts_with($locale ?? get_user_locale(), 'it');
    }
}
