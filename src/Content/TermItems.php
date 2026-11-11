<?php

declare(strict_types=1);

namespace Nucleo\Content;

defined('ABSPATH') || exit;

use WP_Error;
use WP_Taxonomy;
use WP_Term;

/**
 * Termini delle tassonomie ammesse, per slug (docs/contracts/content-items.md, sezione Termini).
 * Gli errori sono { index, slug, path, code, message }: index è la posizione nell'input, -1 per la tassonomia.
 */
final class TermItems
{
    /** @return list<array{taxonomy:string,label:string,hierarchical:bool,modeled:bool,registeredBy:string,postTypes:list<string>}> */
    public static function taxonomies(): array
    {
        $taxonomies = [];
        foreach (ContentTypes::taxonomies() as $taxonomy) {
            $object = get_taxonomy($taxonomy);
            if (!$object instanceof WP_Taxonomy) {
                continue;
            }

            $taxonomies[] = [
                'taxonomy' => $taxonomy,
                'label' => (string) $object->labels->name,
                'hierarchical' => (bool) $object->hierarchical,
                'modeled' => ContentTypes::modeled('taxonomy', $taxonomy),
                'registeredBy' => ContentTypes::origin('taxonomy', $taxonomy),
                'postTypes' => array_values(array_filter((array) $object->object_type, ContentTypes::allowsType(...))),
            ];
        }

        return $taxonomies;
    }

    /**
     * Tutti i termini: genitori prima dei figli, fratelli per nome. Tassonomia non ammessa: [].
     *
     * @return list<array<string,mixed>>
     */
    public static function get(string $taxonomy): array
    {
        if (!ContentTypes::allowsTaxonomy($taxonomy)) {
            return [];
        }

        $found = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
        if (!is_array($found)) {
            return [];
        }

        $slugs = [];
        $children = [];
        foreach ($found as $term) {
            $slugs[$term->term_id] = $term->slug;
            $children[$term->parent][] = $term;
        }

        $fields = FieldValues::fieldsFor(['taxonomy' => $taxonomy]);
        $out = [];
        foreach (self::tree($children, 0) as $term) {
            $item = [
                'slug' => $term->slug,
                'name' => $term->name,
                'description' => $term->description,
                'parent' => $slugs[$term->parent] ?? null,
            ];
            if ($fields !== []) {
                $item['fields'] = ContentRefs::exportFields($fields, FieldValues::get('term', $term->term_id));
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Crea o aggiorna i termini per slug. Un termine con errori non scrive nulla; gli altri proseguono.
     * I genitori della stessa chiamata si scrivono prima dei figli. In dryRun nulla si scrive: action prevista, id null.
     *
     * @param list<array<string,mixed>> $terms
     * @param array<string,string> $media
     * @param list<string> $pending
     * @return array{created:int, updated:int, results:list<array<string,mixed>>, errors:list<array<string,mixed>>}
     */
    public static function set(string $taxonomy, array $terms, bool $dryRun = false, array $media = [], array $pending = []): array
    {
        $object = get_taxonomy($taxonomy);
        if (!$object instanceof WP_Taxonomy || !ContentTypes::allowsTaxonomy($taxonomy)) {
            return self::report([], [self::error(-1, '', '', 'unknown_taxonomy')]);
        }

        $errors = [];
        $items = [];
        $seen = [];
        foreach ($terms as $index => $term) {
            if (!is_array($term)) {
                $errors[] = self::error($index, '', '', 'invalid_input');
                continue;
            }
            $slug = is_string($term['slug'] ?? null) ? $term['slug'] : '';
            if ($slug !== '' && isset($seen[$slug])) {
                $errors[] = self::error($index, $slug, '/slug', 'duplicate');
                continue;
            }
            $seen[$slug] = true;
            $items[$index] = ['term' => $term, 'slug' => $slug];
        }

        $refs = new ContentRefs($media, $pending, $dryRun);
        $known = [];
        $results = [];
        foreach (self::order($items) as $index) {
            $term = $items[$index]['term'];
            $slug = $items[$index]['slug'];
            $issues = [];

            $existing = $slug === '' ? null : get_term_by('slug', $slug, $taxonomy);
            $existing = $existing instanceof WP_Term ? $existing : null;

            $allowed = $existing === null
                ? current_user_can($object->cap->edit_terms)
                : current_user_can('edit_term', $existing->term_id);
            if (!$allowed) {
                $issues[] = self::issue('', 'forbidden');
            }

            if ($slug === '' || $slug !== sanitize_title($slug)) {
                $issues[] = self::issue('/slug', 'invalid_value');
            }

            $hasName = array_key_exists('name', $term);
            $name = is_string($term['name'] ?? null) ? trim($term['name']) : '';
            if ($name === '' && ($existing === null || $hasName)) {
                $issues[] = self::issue('/name', 'required');
            }

            $hasDescription = array_key_exists('description', $term);
            if ($hasDescription && !is_string($term['description'])) {
                $issues[] = self::issue('/description', 'invalid_value');
            }
            $description = is_string($term['description'] ?? null) ? $term['description'] : '';

            $parentId = array_key_exists('parent', $term) ? 0 : null;
            if (array_key_exists('parent', $term) && $term['parent'] !== null) {
                [$parentId, $parentIssue] = self::parent($taxonomy, (bool) $object->hierarchical, $term['parent'], $known, $dryRun, $pending);
                if ($parentIssue !== null) {
                    $issues[] = self::issue('/parent', $parentIssue);
                }
            }

            $values = null;
            if (array_key_exists('fields', $term)) {
                $input = $term['fields'];
                if (!is_array($input) || ($input !== [] && array_is_list($input))) {
                    $issues[] = self::issue('/fields', 'invalid_value');
                } else {
                    $importErrors = [];
                    $values = $refs->importFields(FieldValues::fieldsFor(['taxonomy' => $taxonomy]), $input, '/fields', $importErrors);
                    foreach ($importErrors as $error) {
                        $issues[] = $error;
                    }
                    foreach (FieldValues::validate(['taxonomy' => $taxonomy], $values) as $error) {
                        $issues[] = ['path' => '/fields' . $error['path'], 'code' => $error['code'], 'message' => $error['message']];
                    }
                }
            }

            if ($issues !== []) {
                foreach ($issues as $issue) {
                    $errors[] = ['index' => $index, 'slug' => $slug] + $issue;
                }
                continue;
            }

            // Un termine identico a quello salvato non si riscrive.
            if ($existing !== null && self::sameAsSaved($existing, $hasName ? $name : null, $hasDescription ? $description : null, $parentId, $values)) {
                $known[$slug] = (int) $existing->term_id;
                $results[] = ['index' => $index, 'slug' => $slug, 'action' => 'unchanged', 'id' => $dryRun ? null : (int) $existing->term_id];
                continue;
            }

            if ($dryRun) {
                $known[$slug] = 0;
                $results[] = ['index' => $index, 'slug' => $slug, 'action' => $existing === null ? 'created' : 'updated', 'id' => null];
                continue;
            }

            if ($existing === null) {
                $saved = wp_insert_term($name, $taxonomy, ['slug' => $slug, 'description' => $description, 'parent' => max(0, $parentId ?? 0)]);
                $action = 'created';
            } else {
                $args = ['slug' => $slug];
                if ($hasName) {
                    $args['name'] = $name;
                }
                if ($hasDescription) {
                    $args['description'] = $description;
                }
                if ($parentId !== null) {
                    $args['parent'] = max(0, $parentId);
                }
                $saved = wp_update_term($existing->term_id, $taxonomy, $args);
                $action = 'updated';
            }
            if ($saved instanceof WP_Error) {
                $errors[] = ['index' => $index, 'slug' => $slug, 'path' => '', 'code' => 'error', 'message' => $saved->get_error_message()];
                continue;
            }

            $id = (int) $saved['term_id'];
            $known[$slug] = $id;
            if ($values !== null) {
                $written = FieldValues::set('term', $id, $values);
                foreach ($written['errors'] ?? [] as $error) {
                    $errors[] = ['index' => $index, 'slug' => $slug, 'path' => '/fields' . $error['path'], 'code' => $error['code'], 'message' => $error['message']];
                }
            }
            $results[] = ['index' => $index, 'slug' => $slug, 'action' => $action, 'id' => $id];
        }

        usort($errors, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);
        usort($results, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        return self::report($results, $errors);
    }

    /**
     * Albero dei termini: i figli di ogni genitore nell'ordine ricevuto, ogni figlio dopo il suo genitore.
     *
     * @param array<int,list<WP_Term>> $children
     * @return list<WP_Term>
     */
    private static function tree(array $children, int $parent): array
    {
        $out = [];
        foreach ($children[$parent] ?? [] as $term) {
            $out[] = $term;
            foreach (self::tree($children, (int) $term->term_id) as $descendant) {
                $out[] = $descendant;
            }
        }

        return $out;
    }

    /**
     * Indici di input nell'ordine di scrittura: un termine dopo il suo genitore, se il genitore è nella stessa chiamata.
     * Un ciclo resta in coda: il genitore non si trova e dà not_found.
     *
     * @param array<int,array{term:array<string,mixed>,slug:string}> $items
     * @return list<int>
     */
    private static function order(array $items): array
    {
        $inCall = [];
        foreach ($items as $item) {
            if ($item['slug'] !== '') {
                $inCall[$item['slug']] = true;
            }
        }

        $placed = [];
        $ordered = [];
        $rest = array_keys($items);
        while ($rest !== []) {
            $deferred = [];
            foreach ($rest as $index) {
                $parent = $items[$index]['term']['parent'] ?? null;
                if (is_string($parent) && isset($inCall[$parent]) && !isset($placed[$parent])) {
                    $deferred[] = $index;
                    continue;
                }
                $ordered[] = $index;
                $placed[$items[$index]['slug']] = true;
            }
            if (count($deferred) === count($rest)) {
                return array_merge($ordered, $deferred);
            }
            $rest = $deferred;
        }

        return $ordered;
    }

    /**
     * Id del genitore: un termine di questa chiamata già scritto (in dryRun, già valido), un termine esistente, o in
     * dryRun un simbolo in pending. Ritorna [id, codice d'errore o null].
     *
     * @param array<string,int> $known
     * @param list<string> $pending
     * @return array{0:?int,1:?string}
     */
    private static function parent(string $taxonomy, bool $hierarchical, mixed $parent, array $known, bool $dryRun, array $pending): array
    {
        if (!$hierarchical || !is_string($parent) || $parent === '') {
            return [null, 'invalid_value'];
        }
        if (isset($known[$parent])) {
            return [$known[$parent], null];
        }
        if ($dryRun && in_array('term:' . $taxonomy . '/' . $parent, $pending, true)) {
            return [0, null];
        }

        $found = get_term_by('slug', $parent, $taxonomy);

        return $found instanceof WP_Term ? [(int) $found->term_id, null] : [null, 'not_found'];
    }

    /**
     * Vero se scrivere non cambierebbe nulla. Nel dubbio falso.
     *
     * @param array<string,mixed>|null $values
     */
    private static function sameAsSaved(WP_Term $existing, ?string $name, ?string $description, ?int $parentId, ?array $values): bool
    {
        if ($name !== null && $name !== html_entity_decode($existing->name, ENT_QUOTES)) {
            return false;
        }
        if ($description !== null && $description !== $existing->description) {
            return false;
        }
        if ($parentId !== null && max(0, $parentId) !== (int) $existing->parent) {
            return false;
        }
        if ($values !== null) {
            $saved = FieldValues::get('term', (int) $existing->term_id);
            foreach ($values as $key => $value) {
                if (wp_json_encode($value) !== wp_json_encode($saved[$key] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array{path:string,code:string,message:string} */
    private static function issue(string $path, string $code): array
    {
        return ['path' => $path, 'code' => $code, 'message' => self::message($code)];
    }

    /** @return array{index:int,slug:string,path:string,code:string,message:string} */
    private static function error(int $index, string $slug, string $path, string $code): array
    {
        return ['index' => $index, 'slug' => $slug, 'path' => $path, 'code' => $code, 'message' => self::message($code)];
    }

    /**
     * @param list<array<string,mixed>> $results
     * @param list<array<string,mixed>> $errors
     * @return array{created:int, updated:int, unchanged:int, results:list<array<string,mixed>>, errors:list<array<string,mixed>>}
     */
    private static function report(array $results, array $errors): array
    {
        return [
            'created' => count(array_filter($results, static fn (array $r): bool => $r['action'] === 'created')),
            'updated' => count(array_filter($results, static fn (array $r): bool => $r['action'] === 'updated')),
            'unchanged' => count(array_filter($results, static fn (array $r): bool => $r['action'] === 'unchanged')),
            'results' => $results,
            'errors' => $errors,
        ];
    }

    private static function message(string $code): string
    {
        return match ($code) {
            'invalid_value' => __('Invalid value.', 'nucleo'),
            'required' => __('Required value.', 'nucleo'),
            'not_found' => __('Reference not found.', 'nucleo'),
            'duplicate' => __('Duplicate slug in this call.', 'nucleo'),
            'unknown_taxonomy' => __('Unknown taxonomy.', 'nucleo'),
            'invalid_input' => __('Invalid input.', 'nucleo'),
            'forbidden' => __('Permission denied.', 'nucleo'),
            default => __('Term not saved.', 'nucleo'),
        };
    }
}
