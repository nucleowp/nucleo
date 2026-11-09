<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

use InvalidArgumentException;
use Nucleo\Services;
use Nucleo\Tokens\FontFallback;
use Nucleo\Tokens\FontImporter;
use Nucleo\Tokens\TokenSet;
use WP_Error;

/** Scrive l'artefatto 'tokens' (@font-face locali + CSS delle variabili) nell'ArtifactStore. */
final class TokensPublisher
{
    public static function install(): void
    {
        add_option('nucleo_tokens', [], '', false);
        self::publish();
    }

    /** Stack dei font con il font di ripiego metrico dopo la prima famiglia. L'opzione salvata non cambia. */
    private static function withFontFallbacks(TokenSet $tokens): TokenSet
    {
        $groups = $tokens->toArray();
        foreach ($groups['font'] ?? [] as $name => $stack) {
            if (is_string($stack)) {
                $groups['font'][$name] = FontFallback::withFallback($stack);
            }
        }

        return TokenSet::fromArray($groups);
    }

    public static function publish(): void
    {
        $css = FontImporter::fontFaceCss() . self::withFontFallbacks(Services::tokens())->toCss();
        $previous = Services::store()->get('tokens');
        $version = substr(sha1($css), 0, 8);
        Services::store()->put('tokens', [
            'css' => $css,
            'version' => $version,
            'preload' => FontImporter::preloads(),
        ]);
        // theme.json dei token per il tema: lo legge senza caricare le classi dei token a ogni pagina.
        update_option('nucleo_theme_json', Services::tokens()->toThemeJson(), true);

        // Le pagine contengono i token inline: con CSS diverso le copie in cache sono vecchie.
        if (self::changed($previous, $version)) {
            CachePurger::purgeAll();
            do_action('nucleo/tokens_published', $version);
        }
    }

    /** @param array<string,mixed>|null $previous artefatto precedente */
    public static function changed(?array $previous, string $version): bool
    {
        return ($previous['version'] ?? null) !== $version;
    }

    /**
     * Valida i gruppi, salva l'opzione e pubblica l'artefatto.
     * @param array<string,array<int|string,mixed>> $groups
     */
    public static function update(array $groups): TokenSet|WP_Error
    {
        try {
            $set = TokenSet::fromArray($groups);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('invalid_tokens', $e->getMessage());
        }

        add_option('nucleo_tokens', [], '', false);
        update_option('nucleo_tokens', $groups, false);
        Services::reset();
        self::publish();

        return $set;
    }
}
