<?php

declare(strict_types=1);

namespace Nucleo\Admin;

/**
 * Risolve le regole di Registry per l'utente corrente: ok | hidden | locked | explain | readonly.
 * Ordine: capability → feature → plugin → edizione → scrittura; vince la prima condizione che manca.
 * La UI legge il risultato in nucleoAdmin.nav; le ability verificano capability ed edizione per conto loro.
 */
final class Access
{
    public const OK = 'ok';
    public const HIDDEN = 'hidden';
    public const LOCKED = 'locked';
    public const EXPLAIN = 'explain';
    public const READONLY = 'readonly';

    /**
     * @param array<string, mixed> $rule Regola di Registry (voce, scheda o opzione).
     * @param array{can: callable(string): bool, edition?: string, plugins: array<string, bool>, features: array<string, bool>} $ctx
     * @return array{access: string, reason: ?string} reason: capability | feature | plugin | edition | null
     */
    public static function resolve(array $rule, array $ctx): array
    {
        if (isset($rule['cap']) && !$ctx['can']($rule['cap'])) {
            return ['access' => self::HIDDEN, 'reason' => 'capability'];
        }
        if (isset($rule['feature']) && !($ctx['features'][$rule['feature']] ?? false)) {
            return ['access' => self::HIDDEN, 'reason' => 'feature'];
        }
        if (!empty($rule['plugins'])) {
            $active = array_filter($rule['plugins'], static fn (string $id): bool => (bool) ($ctx['plugins'][$id] ?? false));
            if ($active === []) {
                return ['access' => $rule['missing'] ?? self::HIDDEN, 'reason' => 'plugin'];
            }
        }
        if (isset($rule['write']) && !$ctx['can']($rule['write'])) {
            return ['access' => self::READONLY, 'reason' => 'capability'];
        }

        return ['access' => self::OK, 'reason' => null];
    }

    /** @return array{can: callable(string): bool, edition: string, plugins: array<string, bool>, features: array<string, bool>} */
    public static function context(): array
    {
        return [
            'can' => static fn (string $cap): bool => current_user_can($cap),
            'plugins' => self::plugins(),
            'features' => [],
        ];
    }

    /**
     * Plugin rilevati: woocommerce, polylang, wpml, yoast, rankmath.
     *
     * @return array<string, bool>
     */
    public static function plugins(): array
    {
        return [
            'woocommerce' => class_exists('WooCommerce'),
            'polylang' => defined('POLYLANG_VERSION'),
            'wpml' => defined('ICL_SITEPRESS_VERSION'),
            'yoast' => defined('WPSEO_VERSION'),
            'rankmath' => class_exists('RankMath'),
        ];
    }

    /** @return array{plugins: array<string, bool>, state: array{scanned: bool}, features: array<string, bool>} */
    public static function env(): array
    {
        return [
            'plugins' => self::plugins(),
            'state' => ['scanned' => get_transient(Menu::BADGE_TRANSIENT) !== false],
            'features' => [],
        ];
    }

    /**
     * Voci visibili (non hidden) con schede e opzioni risolte, nell'ordine di Registry.
     *
     * @param array{can: callable(string): bool, edition: string, plugins: array<string, bool>, features: array<string, bool>}|null $ctx null = context()
     * @return list<array{slug: string, label: string, icon: string, url: string, access: string, reason: ?string, pro: bool, tabs: array<string, array{access: string, reason: ?string}>, options: array<string, array{access: string, reason: ?string, plugins: list<string>}>}>
     */
    public static function nav(?array $ctx = null): array
    {
        $ctx ??= self::context();
        $free = ($ctx['edition'] ?? 'free') === 'free';
        $nav = [];

        foreach (Registry::items() as $slug => $rule) {
            $item = self::resolve($rule, $ctx);
            if ($item['access'] === self::HIDDEN) {
                continue;
            }

            $tabs = [];
            $tabLocked = false;
            foreach ($rule['tabs'] ?? [] as $key => $tab) {
                $tab += ['cap' => $rule['cap']];
                $resolved = self::resolve($tab, $ctx);
                if ($resolved['access'] === self::HIDDEN) {
                    continue;
                }
                $tabLocked = $tabLocked || $resolved['access'] === self::LOCKED;
                $tabs[$key] = $resolved;
            }

            $options = [];
            foreach ($rule['options'] ?? [] as $key => $option) {
                $options[$key] = self::resolve($option, $ctx) + ['plugins' => array_values($option['plugins'] ?? [])];
            }

            $nav[] = [
                'slug' => $slug,
                'label' => $free && isset($rule['freeLabel']) ? $rule['freeLabel'] : $rule['label'],
                'icon' => $rule['icon'] ?? '',
                'url' => admin_url('admin.php?page=' . $slug),
                'access' => $item['access'],
                'reason' => $item['reason'],
                'pro' => $free && ($item['access'] === self::LOCKED || $tabLocked),
                'tabs' => $tabs,
                'options' => $options,
            ];
        }

        return $nav;
    }
}
