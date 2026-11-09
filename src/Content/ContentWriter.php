<?php

declare(strict_types=1);

namespace Nucleo\Content;

use Nucleo\Services;
use WP_Error;
use WP_Post;
use WP_Post_Type;
use WP_Taxonomy;
use WP_Term;

/**
 * Scrittura degli elementi dei tipi di contenuto per slug (docs/contracts/content-items.md): crea, aggiorna o cestina.
 * Un elemento con errori non scrive nulla; gli altri della stessa chiamata proseguono.
 *
 * $media e $pending: riferimenti simbolici, come in ContentRefs. $canPublish: se falso, blocca publish, future e private.
 */
final class ContentWriter
{
    private const STATUSES = ['draft', 'publish', 'private', 'future', 'pending', 'trash'];
    private const PUBLISHING = ['publish', 'future', 'private'];
    /** 'base' è l'impronta letta con content-list: si controlla e non si salva. */
    private const KEYS = ['type', 'slug', 'title', 'status', 'date', 'excerpt', 'body', 'featuredImage', 'terms', 'fields', 'parent', 'menuOrder', 'base'];
    /** Chiavi di sola lettura date da content-list e content-get: in scrittura si ignorano, così un elemento letto si può reinviare. */
    private const READ_ONLY = ['managedBy', 'hash', 'protected', 'id'];
    private const DATE_FORMATS = ['Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d'];

    private readonly ContentRefs $refs;

    private readonly ?\Closure $canPublish;

    /** @var array<string,?int> "tipo:slug" → ID (null in dryRun) degli elementi elaborati con successo in questa chiamata */
    private array $handled = [];

    /**
     * @param array<string,string> $media mappa file → URL dal manifest
     * @param list<string> $pending simboli che il push creerà
     * @param ?callable(): bool $canPublish
     */
    public function __construct(
        private readonly bool $dryRun = false,
        array $media = [],
        private readonly array $pending = [],
        ?callable $canPublish = null,
    ) {
        $this->refs = new ContentRefs($media, $pending, $dryRun);
        $this->canPublish = $canPublish === null ? null : \Closure::fromCallable($canPublish);
    }

    /**
     * @param list<mixed> $items
     * @return array{created:int, updated:int, unchanged:int, trashed:int, results:list<array<string,mixed>>, errors:list<array<string,mixed>>}
     */
    public function upsert(array $items): array
    {
        /** @var array{created:int, updated:int, unchanged:int, trashed:int, results:list<array<string,mixed>>, errors:list<array<string,mixed>>} $out */
        $out = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'trashed' => 0, 'results' => [], 'errors' => []];
        $this->handled = [];
        $seen = [];

        foreach (array_values($items) as $index => $item) {
            $type = is_array($item) && is_string($item['type'] ?? null) ? $item['type'] : '';
            $slug = is_array($item) && is_string($item['slug'] ?? null) ? $item['slug'] : '';
            $errors = [];

            if ($type !== '' && $slug !== '') {
                if (isset($seen[$type . ':' . $slug])) {
                    self::fail($errors, 'duplicate', '/slug');
                }
                $seen[$type . ':' . $slug] = true;
            }
            $result = $errors === [] ? $this->write($item, $errors) : null;

            foreach ($errors as $error) {
                $out['errors'][] = ['index' => $index, 'slug' => $slug, 'type' => $type] + $error;
            }
            if ($result === null) {
                continue;
            }

            if ($result['action'] === 'created') {
                $out['created']++;
            } elseif ($result['action'] === 'updated') {
                $out['updated']++;
            } elseif ($result['action'] === 'unchanged') {
                $out['unchanged']++;
            } else {
                $out['trashed']++;
            }
            $entry = ['index' => $index, 'slug' => $slug, 'action' => $result['action'], 'id' => $result['id']];
            if (!$this->dryRun && $result['id'] !== null) {
                $entry['hash'] = $this->savedHash($result['id']);
            }
            $out['results'][] = $entry;
            if ($result['action'] !== 'trashed') {
                $this->handled[$type . ':' . $slug] = $result['id'];
            }
        }

        return $out;
    }

    /**
     * Valida un elemento e, senza errori, lo scrive (o ne simula la scrittura con dryRun).
     *
     * @param list<array{code:string,path:string,message:string}> $errors
     * @return array{action:string,id:?int}|null null se ci sono errori
     */
    private function write(mixed $item, array &$errors): ?array
    {
        if (!is_array($item)) {
            self::fail($errors, 'invalid_input', '');
            return null;
        }
        foreach (array_keys($item) as $key) {
            if (!in_array((string) $key, self::KEYS, true) && !in_array((string) $key, self::READ_ONLY, true)) {
                self::fail($errors, 'unknown_field', '/' . $key);
            }
        }

        $type = $item['type'] ?? null;
        if (!is_string($type) || $type === '') {
            self::fail($errors, 'required', '/type');
            return null;
        }
        if (!ContentTypes::allowsType($type)) {
            self::fail($errors, 'unknown_type', '/type');
            return null;
        }
        $object = get_post_type_object($type);
        if (!$object instanceof WP_Post_Type) {
            self::fail($errors, 'unknown_type', '/type');
            return null;
        }

        $slug = $item['slug'] ?? null;
        if (!is_string($slug) || $slug === '') {
            self::fail($errors, 'required', '/slug');
            return null;
        }
        if (sanitize_title($slug) !== $slug) {
            self::fail($errors, 'invalid_value', '/slug');
            return null;
        }

        $matches = $this->matches($type, $slug);
        if (count($matches) > 1) {
            self::fail($errors, 'ambiguous_slug', '/slug');
            return null;
        }
        $existing = $matches[0] ?? null;
        $creating = $existing === null;
        $canEdit = current_user_can($object->cap->edit_posts);
        if ($existing !== null && !current_user_can('edit_post', $existing->ID)) {
            $canEdit = false;
        }
        if (!$canEdit) {
            self::fail($errors, 'forbidden', '');
            return null;
        }

        if (array_key_exists('base', $item) && !is_string($item['base'])) {
            self::fail($errors, 'invalid_value', '/base');
            return null;
        }
        // base: l'elemento è cambiato dopo la lettura. Vale anche con dryRun.
        if ($existing !== null && isset($item['base']) && $item['base'] !== ContentReader::hash($existing)) {
            self::fail($errors, 'conflict', '/base', sprintf(
                /* translators: 1: content type, 2: slug. */
                __('The item changed on the site after it was read. Via MCP: read it again with content-get or content-list full and resend it with the new hash as base, or without base to overwrite. With the CLI: nucleo pull keeps the site version, nucleo push --force content/%1$s/%2$s.json overwrites it.', 'nucleo'),
                $type,
                $slug,
            ));
            return null;
        }

        $hasStatus = array_key_exists('status', $item);
        $status = $item['status'] ?? null;
        if ($hasStatus && (!is_string($status) || !in_array($status, self::STATUSES, true))) {
            self::fail($errors, 'invalid_value', '/status');
            return null;
        }
        if ($status === 'trash') {
            return $this->trash($existing, $errors);
        }
        $publishing = $hasStatus && in_array($status, self::PUBLISHING, true);
        $published = $existing !== null && in_array($existing->post_status, self::PUBLISHING, true);
        $blocked = $publishing && !current_user_can($object->cap->publish_posts);
        $blocked = $blocked || (($publishing || $published) && !$this->mayPublish());
        if ($blocked) {
            self::fail($errors, 'forbidden', '/status');
            return null;
        }

        $title = null;
        if (array_key_exists('title', $item)) {
            $title = is_string($item['title']) ? sanitize_text_field($item['title']) : null;
            if ($title === null) {
                self::fail($errors, 'invalid_value', '/title');
            } elseif ($title === '') {
                self::fail($errors, 'required', '/title');
            }
        } elseif ($creating) {
            self::fail($errors, 'required', '/title');
        }

        $date = null;
        $dateOk = true;
        if (array_key_exists('date', $item)) {
            $date = $this->parseDate($item['date']);
            $dateOk = $date !== null;
            if (!$dateOk) {
                self::fail($errors, 'invalid_value', '/date');
            }
        }
        if ($status === 'future' && $dateOk) {
            $when = $date ?? $existing?->post_date;
            if ($when === null || $when === '' || str_starts_with($when, '0000')) {
                self::fail($errors, 'required', '/date');
            } elseif ($when <= current_time('mysql')) {
                self::fail($errors, 'invalid_value', '/date');
            }
        }

        $excerpt = null;
        if (array_key_exists('excerpt', $item)) {
            if (is_string($item['excerpt'])) {
                $excerpt = sanitize_textarea_field($item['excerpt']);
            } else {
                self::fail($errors, 'invalid_value', '/excerpt');
            }
        }

        $body = null;
        if (array_key_exists('body', $item)) {
            if (!is_string($item['body'])) {
                self::fail($errors, 'invalid_value', '/body');
            } elseif ($existing !== null && Services::documents()->get($existing->ID) !== null) {
                self::fail($errors, 'managed_by_document', '/body');
            } else {
                $body = current_user_can('unfiltered_html') ? $item['body'] : wp_kses_post($item['body']);
            }
        }

        // false: non toccare; null: togliere; int: ID dell'allegato
        $thumbnail = false;
        if (array_key_exists('featuredImage', $item)) {
            $ref = $item['featuredImage'];
            if ($ref === null) {
                $thumbnail = null;
            } elseif (!(is_string($ref) && $this->isPending($ref))) {
                $attachment = $this->refs->attachment($ref, '/featuredImage', $errors);
                if ($attachment !== null) {
                    $thumbnail = $attachment;
                }
            }
        }

        $terms = [];
        if (array_key_exists('terms', $item)) {
            $this->termIds($type, $item['terms'], $terms, $errors);
        }

        $values = null;
        if (array_key_exists('fields', $item)) {
            $values = $this->fieldValues($type, $item['fields'], $errors);
        }

        $setParent = array_key_exists('parent', $item);
        $parentId = 0;
        if ($setParent) {
            if (!$object->hierarchical) {
                self::fail($errors, 'invalid_value', '/parent', __('This content type is not hierarchical: parent is not allowed. Use menuOrder to order items.', 'nucleo'));
            } elseif ($item['parent'] !== null) {
                $parentId = $this->parentId($type, $slug, $item['parent'], $errors) ?? 0;
            }
        }

        $menuOrder = null;
        // menu_order vale per ogni tipo: WordPress lo salva sempre e content-list e nc/collection ordinano per menu_order.
        if (array_key_exists('menuOrder', $item)) {
            if (!is_int($item['menuOrder']) || $item['menuOrder'] < 0) {
                self::fail($errors, 'invalid_value', '/menuOrder');
            } else {
                $menuOrder = $item['menuOrder'];
            }
        }

        if ($errors !== []) {
            return null;
        }

        // WordPress rende lo slug unico in base allo stato finale: se cambierebbe, non si scrive nulla.
        $finalStatus = $hasStatus ? (string) $status : ($existing->post_status ?? 'draft');
        $finalParent = $setParent ? $parentId : (int) ($existing->post_parent ?? 0);
        if (wp_unique_post_slug($slug, $existing->ID ?? 0, $finalStatus, $type, $finalParent) !== $slug) {
            self::fail($errors, 'slug_conflict', '/slug');
            return null;
        }

        $action = $creating ? 'created' : 'updated';
        $postarr = ['post_type' => $type, 'post_name' => $slug];
        if ($title !== null) {
            $postarr['post_title'] = $title;
        }
        if ($hasStatus) {
            $postarr['post_status'] = $status;
        } elseif ($creating) {
            $postarr['post_status'] = 'draft';
        }
        if ($date !== null) {
            // edit_date: wp_insert_post e wp_update_post rispettano la data anche per le bozze
            $postarr['post_date'] = $date;
            $postarr['post_date_gmt'] = get_gmt_from_date($date);
            $postarr['edit_date'] = true;
        }
        if ($excerpt !== null) {
            $postarr['post_excerpt'] = $excerpt;
        }
        // un body uguale a quello salvato non si riscrive
        if ($body !== null && ($existing === null || $body !== $existing->post_content)) {
            $postarr['post_content'] = $body;
        }
        if ($setParent) {
            $postarr['post_parent'] = $parentId;
        }
        if ($menuOrder !== null) {
            $postarr['menu_order'] = $menuOrder;
        }

        // Un elemento identico a quello salvato non si riscrive: post_modified, revisioni e hook restano fermi.
        if ($existing !== null && self::sameAsSaved($existing, $postarr, $thumbnail, $terms, $values)) {
            return ['action' => 'unchanged', 'id' => $this->dryRun ? null : $existing->ID];
        }
        if ($this->dryRun) {
            return ['action' => $action, 'id' => null];
        }

        if ($creating) {
            $saved = wp_insert_post(wp_slash($postarr), true);
        } else {
            $postarr['ID'] = $existing->ID;
            $saved = wp_update_post(wp_slash($postarr), true);
        }
        if ($saved instanceof WP_Error) {
            self::fail($errors, 'error', '', $saved->get_error_message());
            return null;
        }
        $id = (int) $saved;

        // difesa: il controllo preventivo copre i casi noti; un post creato con uno slug diverso si elimina
        if ($creating && get_post_field('post_name', $id) !== $slug) {
            wp_delete_post($id, true);
            self::fail($errors, 'slug_conflict', '/slug');
            return null;
        }

        $this->saveRelated($id, $thumbnail, $terms, $values, $errors);
        if ($errors === []) {
            return ['action' => $action, 'id' => $id];
        }
        if ($creating) {
            wp_delete_post($id, true);
            return null;
        }

        // aggiornamento: l'elemento esiste già, quindi il risultato c'è insieme all'errore
        return ['action' => $action, 'id' => $id];
    }

    /** Impronta dell'elemento come è salvato ora, senza cache. */
    private function savedHash(int $id): ?string
    {
        clean_post_cache($id);
        $post = get_post($id);

        return $post instanceof WP_Post ? ContentReader::hash($post) : null;
    }

    /**
     * Immagine, termini e campi dopo l'inserimento. Al primo errore si fermano i passi successivi.
     *
     * @param int|false|null $thumbnail false: non toccare; null: togliere; int: ID dell'allegato
     * @param array<string,list<int>> $terms
     * @param array<string,mixed>|null $values
     * @param list<array{code:string,path:string,message:string}> $errors
     */
    private function saveRelated(int $id, int|false|null $thumbnail, array $terms, ?array $values, array &$errors): void
    {
        if ($thumbnail !== false) {
            if ($thumbnail === null) {
                delete_post_thumbnail($id);
            } else {
                set_post_thumbnail($id, $thumbnail);
            }
            if ((int) get_post_thumbnail_id($id) !== (int) $thumbnail) {
                self::fail($errors, 'error', '/featuredImage', __('The featured image could not be saved.', 'nucleo'));
                return;
            }
        }

        foreach ($terms as $taxonomy => $termIds) {
            $set = wp_set_object_terms($id, $termIds, (string) $taxonomy);
            if ($set instanceof WP_Error) {
                self::fail($errors, 'error', '/terms/' . $taxonomy, $set->get_error_message());
                return;
            }
        }

        if ($values !== null) {
            $saved = FieldValues::set('post', $id, $values);
            foreach ($saved['errors'] ?? [] as $error) {
                $errors[] = ['code' => $error['code'], 'path' => '/fields' . $error['path'], 'message' => $error['message']];
            }
        }
    }

    /**
     * Vero se scrivere non cambierebbe nulla. Nel dubbio falso: una scrittura in più è innocua.
     *
     * @param array<string,mixed> $postarr
     * @param array<string,list<int>> $terms
     * @param array<string,mixed>|null $values
     */
    private static function sameAsSaved(WP_Post $existing, array $postarr, int|false|null $thumbnail, array $terms, ?array $values): bool
    {
        // post_content compare in $postarr solo se diverso da quello salvato.
        if (array_key_exists('post_content', $postarr)) {
            return false;
        }
        $columns = ['post_title', 'post_status', 'post_date', 'post_excerpt', 'post_parent', 'menu_order'];
        foreach ($columns as $column) {
            if (array_key_exists($column, $postarr) && (string) $postarr[$column] !== (string) $existing->{$column}) {
                return false;
            }
        }
        if ($thumbnail !== false && (int) $thumbnail !== (int) get_post_thumbnail_id($existing->ID)) {
            return false;
        }
        foreach ($terms as $taxonomy => $ids) {
            $current = wp_get_object_terms($existing->ID, (string) $taxonomy, ['fields' => 'ids']);
            if ($current instanceof WP_Error) {
                return false;
            }
            $want = array_map('intval', $ids);
            $have = array_map('intval', $current);
            sort($want);
            sort($have);
            if ($want !== $have) {
                return false;
            }
        }
        if ($values !== null) {
            $saved = FieldValues::get('post', $existing->ID);
            foreach ($values as $key => $value) {
                if (wp_json_encode($value) !== wp_json_encode($saved[$key] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param list<array{code:string,path:string,message:string}> $errors
     * @return array{action:string,id:?int}|null
     */
    private function trash(?WP_Post $existing, array &$errors): ?array
    {
        if ($existing === null) {
            self::fail($errors, 'not_found', '/status');
            return null;
        }
        if (in_array($existing->ID, ContentTypes::protectedIds(), true)) {
            self::fail($errors, 'protected', '/status');
            return null;
        }
        if (!current_user_can('delete_post', $existing->ID) || !$this->mayPublish()) {
            self::fail($errors, 'forbidden', '/status');
            return null;
        }
        if ($this->dryRun) {
            return ['action' => 'trashed', 'id' => null];
        }
        if (!wp_trash_post($existing->ID)) {
            self::fail($errors, 'error', '', __('The item could not be moved to the trash.', 'nucleo'));
            return null;
        }

        return ['action' => 'trashed', 'id' => $existing->ID];
    }

    /**
     * Termini per tassonomia (slug → ID). Le tassonomie non ammesse per il tipo danno unknown_taxonomy.
     *
     * @param array<string,list<int>> $ids
     * @param list<array{code:string,path:string,message:string}> $errors
     */
    private function termIds(string $type, mixed $input, array &$ids, array &$errors): void
    {
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            self::fail($errors, 'invalid_value', '/terms');
            return;
        }

        $taxonomies = get_object_taxonomies($type);
        foreach ($input as $key => $slugs) {
            $taxonomy = (string) $key;
            $path = '/terms/' . $taxonomy;
            if (!in_array($taxonomy, $taxonomies, true) || !ContentTypes::allowsTaxonomy($taxonomy)) {
                self::fail($errors, 'unknown_taxonomy', $path);
                continue;
            }
            $object = get_taxonomy($taxonomy);
            if (!$object instanceof WP_Taxonomy || !current_user_can($object->cap->assign_terms)) {
                self::fail($errors, 'forbidden', $path);
                continue;
            }
            if (!is_array($slugs) || !array_is_list($slugs)) {
                self::fail($errors, 'invalid_value', $path);
                continue;
            }

            $list = [];
            foreach ($slugs as $i => $termSlug) {
                $termPath = $path . '/' . $i;
                if (!is_string($termSlug) || $termSlug === '') {
                    self::fail($errors, 'invalid_value', $termPath);
                    continue;
                }
                $term = get_term_by('slug', $termSlug, $taxonomy);
                if ($term instanceof WP_Term) {
                    $list[] = (int) $term->term_id;
                } elseif (!$this->isPending('term:' . $taxonomy . '/' . $termSlug)) {
                    self::fail($errors, 'not_found', $termPath);
                }
            }
            $ids[$taxonomy] = $list;
        }
    }

    /**
     * Valori dei campi del tipo, con riferimenti risolti e validati. Con errori restituisce null.
     *
     * @param list<array{code:string,path:string,message:string}> $errors
     * @return array<string,mixed>|null
     */
    private function fieldValues(string $type, mixed $input, array &$errors): ?array
    {
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            self::fail($errors, 'invalid_value', '/fields');
            return null;
        }

        $location = ['postType' => $type];
        $before = count($errors);
        $values = $this->refs->importFields(FieldValues::fieldsFor($location), $input, '/fields', $errors);
        if (count($errors) > $before) {
            return null;
        }

        foreach (FieldValues::validate($location, $values) as $error) {
            $errors[] = ['code' => $error['code'], 'path' => '/fields' . $error['path'], 'message' => $error['message']];
        }

        return $values;
    }

    /**
     * ID del genitore: stesso tipo, esistente o già elaborato in questa chiamata. In dryRun basta che sia in pending.
     *
     * @param list<array{code:string,path:string,message:string}> $errors
     */
    private function parentId(string $type, string $slug, mixed $parent, array &$errors): ?int
    {
        if (!is_string($parent) || $parent === '' || $parent === $slug) {
            self::fail($errors, 'invalid_value', '/parent');
            return null;
        }

        $key = $type . ':' . $parent;
        if (array_key_exists($key, $this->handled)) {
            return (int) $this->handled[$key];
        }
        $matches = $this->matches($type, $parent);
        if (count($matches) > 1) {
            self::fail($errors, 'ambiguous_slug', '/parent');
            return null;
        }
        if ($matches !== []) {
            return $matches[0]->ID;
        }
        if ($this->isPending($key)) {
            return 0;
        }

        self::fail($errors, 'not_found', '/parent');
        return null;
    }

    /** @return list<WP_Post> fino a due elementi con lo slug, qualunque stato tranne il cestino */
    private function matches(string $type, string $slug): array
    {
        return array_values(get_posts([
            'name' => $slug,
            'post_type' => $type,
            'post_status' => 'any', // WordPress esclude il cestino da 'any'
            'orderby' => 'ID',
            'order' => 'ASC',
            'numberposts' => 2,
        ]));
    }

    /** Il chiamante può pubblicare: senza $canPublish sì, con $canPublish quello che risponde. */
    private function mayPublish(): bool
    {
        return $this->canPublish === null || ($this->canPublish)() === true;
    }

    /** Data locale del sito nel formato di post_date, o null se non è in un formato ammesso. */
    private function parseDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        foreach (self::DATE_FORMATS as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    private function isPending(string $value): bool
    {
        return $this->dryRun && in_array($value, $this->pending, true);
    }

    /** @param list<array{code:string,path:string,message:string}> $errors */
    private static function fail(array &$errors, string $code, string $path, ?string $message = null): void
    {
        $errors[] = ['code' => $code, 'path' => $path, 'message' => $message ?? self::message($code)];
    }

    private static function message(string $code): string
    {
        return match ($code) {
            'required' => __('Required value missing.', 'nucleo'),
            'unknown_field' => __('Unknown field.', 'nucleo'),
            'unknown_type' => __('Unknown content type.', 'nucleo'),
            'unknown_taxonomy' => __('Taxonomy not allowed for this content type.', 'nucleo'),
            'not_found' => __('Reference not found.', 'nucleo'),
            'forbidden' => __('Not allowed.', 'nucleo'),
            'duplicate' => __('Duplicate item in this request.', 'nucleo'),
            'managed_by_document' => __('Managed by a Nucleo document.', 'nucleo'),
            'invalid_input' => __('Invalid item.', 'nucleo'),
            'slug_conflict' => __('WordPress assigned a different slug.', 'nucleo'),
            'ambiguous_slug' => __('More than one item has this slug.', 'nucleo'),
            'protected' => __('Protected page: it cannot be trashed.', 'nucleo'),
            default => __('Invalid value.', 'nucleo'),
        };
    }
}
