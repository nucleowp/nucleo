<?php

declare(strict_types=1);

namespace Nucleo\Site;

use Nucleo\Compiler\LinkRefs;
use Nucleo\Compiler\RebuildQueue;

/** Menu di navigazione WordPress: creazione per slug, sostituzione delle voci ed esportazione nel formato @voce-menu. */
final class Menus
{
    /** Profondità massima delle voci, radice compresa. */
    private const MAX_DEPTH = 3;

    /** Crea il menu con questo slug, o ne aggiorna il nome se è diverso. @return int|\WP_Error ID del menu */
    public static function ensure(string $slug, string $name): int|\WP_Error
    {
        $menu = get_term_by('slug', $slug, 'nav_menu');
        if ($menu instanceof \WP_Term) {
            if ($menu->name !== $name) {
                // Lo slug va ripassato: l'aggiornamento del nome lo rigenererebbe dal nuovo nome.
                $updated = wp_update_term((int) $menu->term_id, 'nav_menu', ['name' => $name, 'slug' => $slug]);
                if (is_wp_error($updated)) {
                    return $updated;
                }
            }
            return (int) $menu->term_id;
        }

        // wp_create_nav_menu() deriva lo slug dal nome: qui lo slug è quello richiesto.
        $created = wp_insert_term($name, 'nav_menu', ['slug' => $slug]);
        if (is_wp_error($created)) {
            return $created;
        }
        return (int) $created['term_id'];
    }

    /**
     * Cancella tutte le voci del menu e crea le nuove, in ordine, con i figli sotto il genitore.
     * Pagine, articoli e altri bersagli si risolvono prima di cancellare: un bersaglio mancante non lascia il menu a metà.
     * @param list<array<mixed>> $items
     * @param callable(string,string):?int $resolve ($tipo di post, $slug) → ID del post o null
     * @return bool|\WP_Error true in caso di successo
     */
    public static function replaceItems(int $menuId, array $items, callable $resolve): bool|\WP_Error
    {
        $invalid = self::validate($items);
        if ($invalid !== null) {
            return new \WP_Error('invalid_items', $invalid);
        }

        $prepared = self::prepare($items, $resolve);
        if (is_wp_error($prepared)) {
            return $prepared;
        }

        foreach (wp_get_nav_menu_items($menuId, ['post_status' => 'any']) ?: [] as $old) {
            wp_delete_post((int) $old->ID, true);
        }

        $created = self::create($menuId, $prepared);
        // I layout che usano il menu si ricompilano subito: renderizzati dopo la scrittura mostrano le voci nuove.
        RebuildQueue::run();
        return $created;
    }

    /**
     * Risolve le voci senza scrivere nulla: stesso errore di replaceItems per un bersaglio mancante.
     * @param list<array<mixed>> $items
     * @param callable(string,string):?int $resolve ($tipo di post, $slug) → ID del post o null
     * @param list<string> $planned termini che lo stesso push crea ("term:<tassonomia>/<slug>"): valgono come trovati
     */
    public static function check(array $items, callable $resolve, array $planned = []): true|\WP_Error
    {
        $prepared = self::prepare($items, $resolve, $planned);

        return $prepared instanceof \WP_Error ? $prepared : true;
    }

    /**
     * Contenuto pubblicato per tipo e slug, per menu e impostazioni del sito. Per i tipi gerarchici vale il percorso
     * ("servizi/consulenza"); uno slug senza "/" vale anche per una pagina figlia, se un solo contenuto ha quel post_name.
     * Così restano validi i file esportati prima che le pagine figlie si esportassero con il percorso.
     */
    public static function findPost(string $type, string $slug): ?\WP_Post
    {
        $post = \Nucleo\Compiler\LinkTargets::findPost($type, $slug);
        if ($post !== null || str_contains($slug, '/') || !is_post_type_hierarchical($type)) {
            return $post;
        }
        $posts = get_posts([
            'name' => $slug,
            'post_type' => $type,
            'post_status' => 'publish',
            'numberposts' => 2,
            'suppress_filters' => false,
        ]);

        return count($posts) === 1 && $posts[0] instanceof \WP_Post ? $posts[0] : null;
    }

    /**
     * Controlla la forma delle voci.
     * @param array<mixed> $items
     * @return ?string messaggio di errore, null se valide
     */
    public static function validate(array $items): ?string
    {
        return self::validateLevel($items, 1);
    }

    /**
     * Elenco dei menu per slug, con voci nel formato @voce-menu.
     * Le voci verso pagine o articoli usano lo slug del post collegato; le altre usano un ref ("<tipo>:<slug>", "term:<tax>/<slug>", "archive:<tipo>", "home").
     * Le voci senza un bersaglio valido o pubblicato vengono saltate e finiscono in $skipped: l'import le rifiuterebbe.
     * @param list<array{menu:string,label:string,reason:string}> $skipped
     * @return array<string,array{name:string,items:list<array<string,mixed>>}>
     */
    public static function export(array &$skipped = []): array
    {
        $out = [];
        foreach (wp_get_nav_menus() as $menu) {
            // Come in MenuRenderer: le proprietà aggiunte da WordPress alla voce si leggono come array.
            $rows = [];
            foreach (wp_get_nav_menu_items((int) $menu->term_id, ['post_status' => 'any']) ?: [] as $item) {
                $rows[] = get_object_vars($item);
            }
            $slug = (string) $menu->slug;
            $out[$slug] = ['name' => (string) $menu->name, 'items' => self::exportItems($rows, 0, $slug, $skipped)];
        }
        return $out;
    }

    /** @param array<mixed> $items */
    private static function validateLevel(array $items, int $depth): ?string
    {
        if ($depth > self::MAX_DEPTH) {
            return sprintf('Menu items can be nested at most %d levels deep.', self::MAX_DEPTH);
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                return 'Each menu item must be an object.';
            }
            $label = $item['label'] ?? null;
            if (!is_string($label) || trim($label) === '') {
                return 'Each menu item needs a label.';
            }

            $targets = array_intersect_key($item, array_flip(['page', 'post', 'url', 'ref']));
            if (count($targets) !== 1) {
                return sprintf('Menu item "%s" needs exactly one of page, post, url or ref.', $label);
            }
            foreach ($targets as $key => $value) {
                if (!is_string($value) || trim($value) === '') {
                    return sprintf('Menu item "%s" has an empty or invalid target.', $label);
                }
                if ($key === 'ref' && !self::validRef($value)) {
                    return sprintf('Menu item "%s" has an invalid ref: %s', $label, $value);
                }
            }

            if (isset($item['children'])) {
                if (!is_array($item['children'])) {
                    return sprintf('Menu item "%s" has invalid children.', $label);
                }
                $error = self::validateLevel($item['children'], $depth + 1);
                if ($error !== null) {
                    return $error;
                }
            }
        }
        return null;
    }

    /**
     * Forma di un ref di voce, senza WordPress: "home", "archive:<tipo>" o un riferimento di LinkRefs senza #ancora.
     */
    private static function validRef(string $ref): bool
    {
        if ($ref === 'home' || preg_match('/^archive:[a-z0-9_-]{1,20}$/D', $ref) === 1) {
            return true;
        }
        $parsed = LinkRefs::parse($ref);

        return $parsed !== null && $parsed['fragment'] === '';
    }

    /**
     * Risolve pagine e articoli in ID di post, senza effetti sul database.
     * @param array<mixed> $items
     * @param callable(string,string):?int $resolve
     * @param list<string> $planned
     * @return list<array<string,mixed>>|\WP_Error
     */
    private static function prepare(array $items, callable $resolve, array $planned = []): array|\WP_Error
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                return new \WP_Error('invalid_items', 'Each menu item must be an object.');
            }
            $label = (string) ($item['label'] ?? '');
            if (isset($item['url'])) {
                $node = ['label' => $label, 'type' => 'custom', 'object' => 'custom', 'objectId' => 0, 'url' => (string) $item['url'], 'children' => []];
            } elseif (isset($item['ref'])) {
                $node = self::prepareRef($label, (string) $item['ref'], $resolve, $planned);
                if ($node instanceof \WP_Error) {
                    return $node;
                }
            } else {
                $type = isset($item['page']) ? 'page' : 'post';
                $slug = (string) ($item[$type] ?? '');
                $id = $resolve($type, $slug);
                if ($id === null || $id <= 0) {
                    return new \WP_Error('menu_target_missing', sprintf(/* translators: %s: slug of the page or post */ __('Page or post not found: %s', 'nucleo'), $slug));
                }
                $node = ['label' => $label, 'type' => 'post_type', 'object' => $type, 'objectId' => $id, 'url' => '', 'children' => []];
            }

            if (!empty($item['children'])) {
                $children = self::prepare(is_array($item['children']) ? $item['children'] : [], $resolve, $planned);
                if (is_wp_error($children)) {
                    return $children;
                }
                $node['children'] = $children;
            }
            $out[] = $node;
        }
        return $out;
    }

    /**
     * Voce di un ref: "home", "archive:<tipo>", "<tipo>:<slug>" (post) o "term:<tassonomia>/<slug>".
     * @param list<string> $planned
     * @return array<string,mixed>|\WP_Error
     */
    private static function prepareRef(string $label, string $ref, callable $resolve, array $planned): array|\WP_Error
    {
        if (!self::validRef($ref)) {
            return new \WP_Error('invalid_items', sprintf('Menu item "%s" has an invalid ref: %s', $label, $ref));
        }

        if ($ref === 'home') {
            return ['label' => $label, 'type' => 'custom', 'object' => 'custom', 'objectId' => 0, 'url' => home_url('/'), 'children' => []];
        }

        if (str_starts_with($ref, 'archive:')) {
            $type = substr($ref, strlen('archive:'));
            $link = post_type_exists($type) ? get_post_type_archive_link($type) : false;
            if (!is_string($link)) {
                return self::missingRef($ref);
            }
            return ['label' => $label, 'type' => 'post_type_archive', 'object' => $type, 'objectId' => 0, 'url' => '', 'children' => []];
        }

        $parsed = LinkRefs::parse($ref);
        if ($parsed === null) {
            return self::missingRef($ref);
        }

        if ($parsed['kind'] === 'term') {
            if (!taxonomy_exists($parsed['type']) || !is_taxonomy_viewable($parsed['type'])) {
                return self::missingRef($ref);
            }
            $term = get_term_by('slug', $parsed['slug'], $parsed['type']);
            if (!$term instanceof \WP_Term && in_array('term:' . $parsed['type'] . '/' . $parsed['slug'], $planned, true)) {
                // Dry-run: il termine esiste solo dopo il push; nessuna voce viene scritta.
                return ['label' => $label, 'type' => 'taxonomy', 'object' => $parsed['type'], 'objectId' => PHP_INT_MAX, 'url' => '', 'children' => []];
            }
            if (!$term instanceof \WP_Term) {
                return self::missingRef($ref);
            }
            return ['label' => $label, 'type' => 'taxonomy', 'object' => $parsed['type'], 'objectId' => (int) $term->term_id, 'url' => '', 'children' => []];
        }

        if (!post_type_exists($parsed['type']) || !is_post_type_viewable($parsed['type'])) {
            return self::missingRef($ref);
        }
        $id = $resolve($parsed['type'], $parsed['slug']);
        if ($id === null || $id <= 0) {
            return self::missingRef($ref);
        }
        return ['label' => $label, 'type' => 'post_type', 'object' => $parsed['type'], 'objectId' => $id, 'url' => '', 'children' => []];
    }

    private static function missingRef(string $ref): \WP_Error
    {
        return new \WP_Error('menu_target_missing', sprintf(/* translators: %s: menu item reference, e.g. term:category/news */ __('Menu target not found: %s', 'nucleo'), $ref));
    }

    /** @param array<mixed> $nodes nodi preparati da prepare() */
    private static function create(int $menuId, array $nodes, int $parentItemId = 0): bool|\WP_Error
    {
        foreach ($nodes as $node) {
            $itemId = wp_update_nav_menu_item($menuId, 0, [
                'menu-item-title' => $node['label'],
                'menu-item-type' => $node['type'],
                'menu-item-object' => $node['object'],
                'menu-item-object-id' => $node['objectId'],
                'menu-item-url' => $node['url'],
                'menu-item-parent-id' => $parentItemId,
                'menu-item-status' => 'publish',
            ]);
            if (is_wp_error($itemId)) {
                return $itemId;
            }
            if (is_array($node['children']) && $node['children'] !== []) {
                $done = self::create($menuId, $node['children'], (int) $itemId);
                if (is_wp_error($done)) {
                    return $done;
                }
            }
        }
        return true;
    }

    /**
     * Voci figlie di $parentDbId, in ordine di menu. Le voci verso bersagli che @voce-menu non esprime
     * (tipi non visibili, post cancellati, termini cancellati) non entrano nell'elenco.
     * @param list<array<string,mixed>> $rows proprietà delle voci di wp_get_nav_menu_items()
     * @return list<array<string,mixed>>
     */
    /**
     * Voci figlie di $parentDbId, in ordine di menu.
     * @param list<array<string,mixed>> $rows proprietà delle voci di wp_get_nav_menu_items()
     * @param list<array{menu:string,label:string,reason:string}> $skipped
     * @return list<array<string,mixed>>
     */
    private static function exportItems(array $rows, int $parentDbId, string $menu, array &$skipped): array
    {
        $items = [];
        foreach ($rows as $row) {
            if ((int) ($row['menu_item_parent'] ?? 0) !== $parentDbId) {
                continue;
            }

            $label = (string) ($row['title'] ?? '');
            $target = self::exportTarget($row);
            if (is_string($target)) {
                $skipped[] = ['menu' => $menu, 'label' => $label, 'reason' => $target];
                continue;
            }

            $node = ['label' => $label] + $target;
            // L'ID del post della voce è quello che menu_item_parent dei figli riporta.
            $children = self::exportItems($rows, (int) ($row['ID'] ?? 0), $menu, $skipped);
            if ($children !== []) {
                $node['children'] = $children;
            }
            $items[] = $node;
        }
        return $items;
    }

    /**
     * Bersaglio di una voce nel formato @voce-menu, o il motivo per cui la voce si salta.
     * @param array<string,mixed> $row
     * @return array<string,string>|string
     */
    private static function exportTarget(array $row): array|string
    {
        $type = (string) ($row['type'] ?? '');
        $object = (string) ($row['object'] ?? '');
        $objectId = (int) ($row['object_id'] ?? 0);

        if ($type === 'custom') {
            $url = (string) ($row['url'] ?? '');

            return $url === home_url('/') || $url === '/' ? ['ref' => 'home'] : ['url' => $url];
        }
        if ($type === 'post_type_archive') {
            return post_type_exists($object) ? ['ref' => 'archive:' . $object] : 'post type not registered: ' . $object;
        }
        if ($type === 'taxonomy') {
            if (!taxonomy_exists($object) || !is_taxonomy_viewable($object)) {
                return 'taxonomy not public: ' . $object;
            }
            $term = get_term($objectId, $object);

            return $term instanceof \WP_Term ? ['ref' => 'term:' . $object . '/' . $term->slug] : 'term deleted';
        }
        if ($type !== 'post_type') {
            return 'unsupported item type: ' . $type;
        }

        $post = get_post($objectId);
        if (!$post instanceof \WP_Post) {
            return 'content deleted';
        }
        if (!post_type_exists($object) || !is_post_type_viewable($object)) {
            return 'post type not public: ' . $object;
        }
        // L'import accetta solo contenuti pubblicati: una voce verso una bozza farebbe fallire tutto il menu.
        if ($post->post_status !== 'publish') {
            return 'content not published (' . $post->post_status . '): ' . $object . ':' . $post->post_name;
        }
        // Una pagina figlia porta il percorso ("servizi/consulenza"): l'import la ritrova con lo stesso valore.
        $slug = is_post_type_hierarchical($object) ? (string) get_page_uri($post) : $post->post_name;
        if ($slug === '') {
            return 'content without a slug';
        }
        if ($object === 'page' || $object === 'post') {
            return [$object => $slug];
        }

        return ['ref' => $object . ':' . $slug];
    }
}
