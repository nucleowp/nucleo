<?php

declare(strict_types=1);

namespace Nucleo\Content;

defined('ABSPATH') || exit;

/** Impostazioni della pagina nel meta _nucleo_page: layout per area, titolo nascosto, larghezza e classi del body. */
final class PageSettings
{
    public const META = '_nucleo_page';
    public const AREAS = ['header', 'before-content', 'single', 'after-content', 'footer'];
    public const WIDTHS = ['normal', 'wide', 'full'];

    public static function register(): void
    {
        // Nelle richieste REST i moduli si caricano su rest_api_init, dopo init: il meta si registra subito.
        if (did_action('init') > 0) {
            self::registerMeta();
        } else {
            add_action('init', [self::class, 'registerMeta']);
        }
        add_filter('body_class', [self::class, 'bodyClasses']);
    }

    /** Espone il meta nella REST per ogni tipo di contenuto con interfaccia e REST. */
    public static function registerMeta(): void
    {
        foreach (get_post_types(['show_ui' => true, 'show_in_rest' => true], 'names') as $postType) {
            register_post_meta($postType, self::META, [
                'single' => true,
                'type' => 'object',
                'show_in_rest' => ['schema' => self::schema()],
                'default' => self::normalize(null),
                'sanitize_callback' => [self::class, 'normalize'],
                'auth_callback' => static fn ($allowed, $metaKey, $postId): bool => current_user_can('edit_post', (int) $postId),
            ]);
        }
    }

    /**
     * Forma completa e pulita del meta: chiavi sconosciute e aree sconosciute scartate.
     *
     * @return array{layouts: array<string,string|int>, hideTitle: bool, width: string, bodyClass: string}
     */
    public static function normalize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $layouts = is_array($raw['layouts'] ?? null) ? $raw['layouts'] : [];

        $out = ['layouts' => [], 'hideTitle' => false, 'width' => 'normal', 'bodyClass' => ''];
        foreach (self::AREAS as $area) {
            $out['layouts'][$area] = self::layoutValue($layouts[$area] ?? null);
        }
        $out['hideTitle'] = ($raw['hideTitle'] ?? false) === true;
        if (in_array($raw['width'] ?? null, self::WIDTHS, true)) {
            $out['width'] = $raw['width'];
        }
        $out['bodyClass'] = self::bodyClassValue($raw['bodyClass'] ?? null);

        return $out;
    }

    /** @return array{layouts: array<string,string|int>, hideTitle: bool, width: string, bodyClass: string} */
    public static function get(int $postId): array
    {
        return self::normalize(get_post_meta($postId, self::META, true));
    }

    /** Null = automatico (regole), 0 = nessun layout, maggiore di 0 = id del layout. Null per aree sconosciute. */
    public static function layoutFor(int $postId, string $area): ?int
    {
        if (!in_array($area, self::AREAS, true)) {
            return null;
        }

        $value = self::get($postId)['layouts'][$area];
        if ($value === 'auto') {
            return null;
        }
        if ($value === 'none') {
            return 0;
        }

        return is_int($value) ? $value : null;
    }

    public static function hideTitle(int $postId): bool
    {
        return self::get($postId)['hideTitle'];
    }

    /**
     * @param list<string> $classes
     * @return list<string>
     */
    public static function bodyClasses(array $classes): array
    {
        if (!is_singular()) {
            return $classes;
        }

        $settings = self::get(get_queried_object_id());
        if ($settings['width'] !== 'normal') {
            $classes[] = 'nc-width-' . $settings['width'];
        }
        if ($settings['hideTitle']) {
            $classes[] = 'nc-hide-title';
        }
        if ($settings['bodyClass'] !== '') {
            foreach (explode(' ', $settings['bodyClass']) as $token) {
                $classes[] = $token;
            }
        }

        return $classes;
    }

    /** @return string|int 'none', 'auto' oppure un intero maggiore di 0 (anche come stringa numerica). */
    private static function layoutValue(mixed $value): string|int
    {
        if ($value === 'none' || $value === 'auto') {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 1 ? $value : 'auto';
    }

    /** Ogni token ripulito con sanitize_html_class; i token vuoti vengono scartati. */
    private static function bodyClassValue(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $tokens = [];
        foreach (preg_split('/\s+/', $value) ?: [] as $token) {
            $class = sanitize_html_class($token);
            if ($class !== '') {
                $tokens[] = $class;
            }
        }

        return implode(' ', $tokens);
    }

    /** @return array<string,mixed> */
    private static function schema(): array
    {
        $layouts = [];
        foreach (self::AREAS as $area) {
            $layouts[$area] = ['type' => ['string', 'integer']];
        }

        return [
            'type' => 'object',
            'properties' => [
                'layouts' => ['type' => 'object', 'properties' => $layouts],
                'hideTitle' => ['type' => 'boolean'],
                'width' => ['type' => 'string', 'enum' => self::WIDTHS],
                'bodyClass' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }
}
