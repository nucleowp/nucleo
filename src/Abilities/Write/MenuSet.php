<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Site\Menus;

/** nucleo/menu-set: crea o aggiorna un menu di navigazione per slug e ne sostituisce le voci. */
final class MenuSet implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/menu-set';
    }

    public function args(): array
    {
        return [
            'label' => __('Set menu', 'nucleo'),
            'description' => 'Creates or updates a navigation menu by slug and replaces all its items. Each item is {label, page | post | url | ref, children}: page and post take the slug of a published page or post; ref points to other targets: "home", "archive:<post-type>", "<post-type>:<slug>" (a published post, hierarchical slugs as paths), "term:<taxonomy>/<slug>"; url is only for external links; children are nested items, up to 3 levels deep. Existing items are removed. If a target is not found, nothing changes.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['slug', 'items'],
                'properties' => [
                    'slug' => ['type' => 'string', 'minLength' => 1, 'description' => 'Menu slug, lowercase, e.g. "principale".'],
                    'name' => ['type' => 'string', 'description' => 'Menu name. Defaults to the slug.'],
                    'items' => [
                        'type' => 'array',
                        'description' => 'Menu items in order. Each item: {label: string, page?: slug, post?: slug, url?: string, ref?: string, children?: items}. Exactly one of page, post, url, ref.',
                        'items' => ['type' => 'object'],
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'slug' => ['type' => 'string'],
                    'count' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Number of items created, children included.'],
                    'error' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
            ],
        ];
    }

    public function execute(array $input): array
    {
        $slug = $input['slug'] ?? null;
        $name = $input['name'] ?? null;
        $raw = $input['items'] ?? null;
        if (
            !is_string($slug) || $slug === '' || sanitize_title($slug) !== $slug
            || !is_array($raw) || ($name !== null && !is_string($name))
        ) {
            return self::failure('invalid_input', __('Fields slug, name or items are not valid. The slug must be lowercase letters, digits and hyphens.', 'nucleo'));
        }
        $name = is_string($name) && trim($name) !== '' ? sanitize_text_field($name) : $slug;

        $items = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                return self::failure('invalid_items', 'Each menu item must be an object.');
            }
            $items[] = $item;
        }

        // Prima di creare o toccare il menu: una voce non valida non lascia un menu vuoto.
        $invalid = Menus::validate($items);
        if ($invalid !== null) {
            return self::failure('invalid_items', $invalid);
        }

        $menuId = Menus::ensure($slug, $name);
        if (is_wp_error($menuId)) {
            return self::failure('menu_failed', $menuId->get_error_message());
        }

        $done = Menus::replaceItems($menuId, $items, static fn (string $type, string $target): ?int => self::resolve($type, $target));
        if (is_wp_error($done)) {
            return self::failure((string) $done->get_error_code(), $done->get_error_message());
        }

        return ['ok' => true, 'id' => $menuId, 'slug' => $slug, 'count' => self::count($items)];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_theme_options');
    }

    /** Articolo pubblicato di questo tipo con questo slug (anche a percorso per i tipi gerarchici), o null. */
    private static function resolve(string $type, string $slug): ?int
    {
        return Menus::findPost($type, $slug)?->ID;
    }

    /** @param array<mixed> $items */
    private static function count(array $items): int
    {
        $n = 0;
        foreach ($items as $item) {
            $children = is_array($item) && is_array($item['children'] ?? null) ? $item['children'] : [];
            $n += 1 + self::count($children);
        }
        return $n;
    }

    /** @return array{ok:false,error:array{code:string,message:string}} */
    private static function failure(string $code, string $message): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    }
}
