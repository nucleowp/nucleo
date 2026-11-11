<?php

declare(strict_types=1);

namespace Nucleo\OAuth;

defined('ABSPATH') || exit;

/** Scope OAuth di Nucleo, con il gruppo di ability e la capability WordPress corrispondenti. */
final class Scopes
{
    public const READ = 'nucleo:read';
    public const WRITE = 'nucleo:write';
    public const PUBLISH = 'nucleo:publish';
    public const DESIGN = 'nucleo:design';
    public const ADMIN = 'nucleo:admin';

    private const ABILITY_SCOPES = [
        'quality-scan' => self::READ,
        'quality-check' => self::READ,
        'assets-report' => self::READ,
        'doc-get' => self::READ,
        'doc-list' => self::READ,
        'layout-explain' => self::READ,
        'layout-list' => self::READ,
        'site-describe' => self::READ,
        'catalog-list' => self::READ,
        'catalog-get' => self::READ,
        'doc-validate' => self::READ,
        'doc-preview' => self::READ,
        'doc-revisions' => self::READ,
        'doc-texts' => self::READ,
        'audit-list' => self::READ,
        'bind-fields' => self::READ,
        'section-list' => self::READ,
        'docs-search' => self::READ,
        'tokens-get' => self::READ,
        'doc-create' => self::WRITE,
        'doc-patch' => self::WRITE,
        'doc-rollback' => self::WRITE,
        'media-import' => self::WRITE,
        'media-upload' => self::WRITE,
        'menu-list' => self::READ,
        'menu-set' => self::DESIGN,
        'site-options-set' => self::ADMIN,
        'icons-set' => self::ADMIN,
        'woo-checkout-fields-set' => self::ADMIN,
        'woo-checkout-parts-set' => self::ADMIN,
        'gap-report' => self::WRITE,
        'gap-list' => self::READ,
        'media-formats' => self::WRITE,
        'doc-texts-set' => self::WRITE,
        'doc-translate' => self::WRITE,
        'doc-publish' => self::PUBLISH,
        'tokens-set' => self::DESIGN,
        'fonts-import' => self::DESIGN,
        'assets-rules-set' => self::DESIGN,
        'layout-conditions-set' => self::DESIGN,
        'assets-audit' => self::DESIGN,
        'css-purge' => self::DESIGN,
        'css-status' => self::DESIGN,
        'css-optimize' => self::DESIGN,
        'css-optimize-apply' => self::DESIGN,
        'css-optimize-off' => self::DESIGN,
        'status' => self::ADMIN,
        'cache-rebuild' => self::ADMIN,
        'site-export' => self::ADMIN,
        'site-import' => self::ADMIN,
        'kit-list' => self::READ,
        'kit-apply' => self::DESIGN,
        'critical-set' => self::DESIGN,
        'settings-get' => self::ADMIN,
        'settings-set' => self::ADMIN,
        'model-get' => self::READ,
        'components-list' => self::READ,
        'model-validate' => self::READ,
        'model-set' => self::DESIGN,
        'fields-get' => self::READ,
        'fields-set' => self::WRITE,
        'content-list' => self::READ,
        'content-get' => self::READ,
        'terms-get' => self::READ,
        'content-upsert' => self::WRITE,
        'terms-set' => self::WRITE,
        'guide' => self::READ,
        'mail-log' => self::ADMIN,
    ];

    private const CAPABILITIES = [
        self::READ => 'edit_posts',
        self::WRITE => 'edit_posts',
        self::PUBLISH => 'publish_pages',
        self::DESIGN => 'edit_theme_options',
        self::ADMIN => 'manage_options',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [self::READ, self::WRITE, self::PUBLISH, self::DESIGN, self::ADMIN];
    }

    /**
     * Scope validi presenti in $space (separati da spazi), nell'ordine di all(), senza duplicati.
     *
     * @return list<string>
     */
    public static function parse(string $space): array
    {
        $requested = array_flip(preg_split('/\s+/', trim($space), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return array_values(array_filter(self::all(), static fn (string $s) => isset($requested[$s])));
    }

    public static function forAbility(string $ability): ?string
    {
        return self::ABILITY_SCOPES[$ability] ?? null;
    }

    public static function capability(string $scope): string
    {
        if (!isset(self::CAPABILITIES[$scope])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException("Unknown scope: $scope");
        }

        return self::CAPABILITIES[$scope];
    }
}
