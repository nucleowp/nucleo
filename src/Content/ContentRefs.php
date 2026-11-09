<?php

declare(strict_types=1);

namespace Nucleo\Content;

/**
 * Riferimenti simbolici dei campi image e relation (docs/contracts/content-items.md).
 * Scrittura: simbolo → ID. Lettura: ID → simbolo. Gli errori sono array { code, path, message }, aggiunti per riferimento.
 *
 * $media: mappa file → URL dal manifest. $pending: simboli che il push creerà (si tolgono solo con $dryRun).
 */
final class ContentRefs
{
    /**
     * @param array<string,string> $media
     * @param list<string> $pending
     */
    public function __construct(
        private readonly array $media = [],
        private readonly array $pending = [],
        private readonly bool $dryRun = false,
    ) {
    }

    /**
     * Simboli → ID nei campi image e relation, anche multiple e nei sottocampi dei repeater. Gli altri valori passano
     * invariati: li valida FieldValues::validate. Un riferimento non risolto dà not_found e valore null: chi chiama non
     * scrive se ci sono errori.
     *
     * @param list<array<string,mixed>> $fields
     * @param array<string,mixed> $values
     * @param list<array{code:string,path:string,message:string}> $errors
     * @param-out list<array{code:string,path:string,message:string}> $errors
     * @return array<string,mixed>
     */
    public function importFields(array $fields, array $values, string $path, array &$errors): array
    {
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field['key']] = $field;
        }

        $out = $values;
        foreach ($values as $key => $value) {
            if (!isset($byKey[$key])) {
                continue;
            }
            [$keep, $resolved] = $this->importValue($byKey[$key], $value, $path . '/' . $key, $errors);
            if ($keep) {
                $out[$key] = $resolved;
            } else {
                unset($out[$key]);
            }
        }

        return $out;
    }

    /**
     * Post per "<post-type>:<slug>" (qualunque stato tranne il cestino) oppure per ID. $types vuoto: qualunque tipo.
     *
     * @param list<string> $types
     * @param list<array{code:string,path:string,message:string}> $errors
     * @param-out list<array{code:string,path:string,message:string}> $errors
     */
    public function post(mixed $ref, array $types, string $path, array &$errors): ?int
    {
        if (is_int($ref) || (is_string($ref) && ctype_digit($ref))) {
            $id = (int) $ref;
            if ($id > 0 && $this->visiblePost($id)) {
                return $id;
            }

            return $this->notFound($path, $errors);
        }
        if (!is_string($ref) || !str_contains($ref, ':')) {
            return $this->notFound($path, $errors);
        }

        [$type, $slug] = explode(':', $ref, 2);
        if ($type === '' || $slug === '' || !ContentTypes::allowsType($type)) {
            return $this->notFound($path, $errors);
        }
        if ($types !== [] && !in_array($type, $types, true)) {
            return $this->invalidValue($path, $errors);
        }

        $ids = get_posts([
            'name' => $slug,
            'post_type' => $type,
            'post_status' => 'any',
            'numberposts' => 20,
            'fields' => 'ids',
        ]);
        foreach ($ids as $id) {
            if (current_user_can('read_post', (int) $id)) {
                return (int) $id;
            }
        }

        return $this->notFound($path, $errors);
    }

    /** Post non nel cestino di un tipo ammesso, che l'utente corrente può leggere. */
    private function visiblePost(int $id): bool
    {
        $post = get_post($id);

        return $post instanceof \WP_Post
            && $post->post_status !== 'trash'
            && ContentTypes::allowsType($post->post_type)
            && current_user_can('read_post', $id);
    }

    /**
     * Allegato per "media:<file>" (prima la mappa media, poi il file originale più recente) oppure per ID.
     *
     * @param list<array{code:string,path:string,message:string}> $errors
     * @param-out list<array{code:string,path:string,message:string}> $errors
     */
    public function attachment(mixed $ref, string $path, array &$errors): ?int
    {
        if (is_int($ref) || (is_string($ref) && ctype_digit($ref))) {
            $id = (int) $ref;
            if ($id > 0 && get_post_type($id) === 'attachment') {
                return $id;
            }

            return $this->notFound($path, $errors);
        }
        if (!is_string($ref) || !str_starts_with($ref, 'media:')) {
            return $this->notFound($path, $errors);
        }

        $file = substr($ref, strlen('media:'));
        if ($file === '') {
            return $this->notFound($path, $errors);
        }

        $url = $this->media[$file] ?? null;
        if (is_string($url) && $url !== '') {
            $id = attachment_url_to_postid($url);
            if ($id > 0) {
                return $id;
            }
        }

        $id = $this->attachmentByFile($file);
        if ($id === null) {
            return $this->notFound($path, $errors);
        }

        return $id;
    }

    /**
     * Riferimento di un allegato: "media:<basename del file originale>", o l'ID se manca il file.
     */
    public static function attachmentRef(int $id): string|int
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== 'attachment' || $post->post_status === 'trash') {
            return $id;
        }

        $path = wp_get_original_image_path($id) ?: get_attached_file($id);
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            return $id;
        }

        return 'media:' . basename($path);
    }

    /**
     * Riferimento di un post: "<post-type>:<slug>", o l'ID se il post manca, è nel cestino o non ha slug.
     */
    public static function postRef(int $id): string|int
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_status === 'trash' || $post->post_name === '') {
            return $id;
        }

        return $post->post_type . ':' . $post->post_name;
    }

    /**
     * ID → riferimenti simbolici nei campi image e relation, anche multiple e nei repeater. Gli altri valori passano invariati.
     *
     * @param list<array<string,mixed>> $fields
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public static function exportFields(array $fields, array $values): array
    {
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field['key']] = $field;
        }

        $out = $values;
        foreach ($values as $key => $value) {
            if (isset($byKey[$key])) {
                $out[$key] = self::exportValue($byKey[$key], $value);
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $field
     * @param list<array{code:string,path:string,message:string}> $errors
     * @param-out list<array{code:string,path:string,message:string}> $errors
     * @return array{0:bool,1:mixed} se tenere il valore, valore risolto
     */
    private function importValue(array $field, mixed $value, string $path, array &$errors): array
    {
        if ($field['type'] === 'repeater') {
            return [true, $this->importRows($field, $value, $path, $errors)];
        }
        if (!in_array($field['type'], ['image', 'relation'], true)) {
            return [true, $value];
        }

        if (!empty($field['multiple']) && is_array($value) && array_is_list($value)) {
            $items = [];
            foreach ($value as $i => $item) {
                if (!$this->isPending($item)) {
                    $items[] = $this->importRef($field, $item, $path . '/' . $i, $errors);
                }
            }

            return $items === [] && $value !== [] ? [false, null] : [true, $items];
        }

        if ($this->isPending($value)) {
            return [false, null];
        }

        return [true, $this->importRef($field, $value, $path, $errors)];
    }

    /**
     * @param array<string,mixed> $field
     * @param list<array{code:string,path:string,message:string}> $errors
     * @param-out list<array{code:string,path:string,message:string}> $errors
     */
    private function importRef(array $field, mixed $input, string $path, array &$errors): mixed
    {
        if ((!is_string($input) && !is_int($input)) || (is_string($input) && trim($input) === '')) {
            return $input;
        }
        if ($field['type'] === 'image') {
            return $this->attachment($input, $path, $errors);
        }

        return $this->post($input, $field['postTypes'] ?? [], $path, $errors);
    }

    /**
     * @param array<string,mixed> $field
     * @param list<array{code:string,path:string,message:string}> $errors
     * @param-out list<array{code:string,path:string,message:string}> $errors
     */
    private function importRows(array $field, mixed $rows, string $path, array &$errors): mixed
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            return $rows;
        }

        $subs = [];
        foreach ($field['fields'] ?? [] as $sub) {
            $subs[$sub['key']] = $sub;
        }

        $out = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                $out[] = $row;
                continue;
            }

            $cells = $row;
            foreach ($row as $key => $cell) {
                if (!isset($subs[$key])) {
                    continue;
                }
                [$keep, $resolved] = $this->importValue($subs[$key], $cell, $path . '/' . $i . '/' . $key, $errors);
                if ($keep) {
                    $cells[$key] = $resolved;
                } else {
                    unset($cells[$key]);
                }
            }
            $out[] = $cells;
        }

        return $out;
    }

    /**
     * Allegato più recente il cui file originale ha questo nome. _wp_attached_file vale "file" o "anno/mese/file";
     * WordPress salva le immagini grandi come "<stem>-scaled.<ext>", quindi si cerca anche quel nome. Confronto esatto
     * oppure suffisso "/nome". Si esaminano al massimo 20 candidati, dal più recente.
     */
    private function attachmentByFile(string $file): ?int
    {
        global $wpdb;

        // Senza estensione il secondo nome ripete il primo: la query ha sempre quattro segnaposto.
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $scaled = $ext !== '' ? pathinfo($file, PATHINFO_FILENAME) . '-scaled.' . $ext : $file;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
             AND m.meta_key = '_wp_attached_file'
             AND (m.meta_value = %s OR m.meta_value LIKE %s OR m.meta_value = %s OR m.meta_value LIKE %s)
             ORDER BY p.ID DESC LIMIT 20",
            $file,
            '%/' . $wpdb->esc_like($file),
            $scaled,
            '%/' . $wpdb->esc_like($scaled),
        ));

        foreach (array_map('intval', $ids) as $id) {
            $original = wp_get_original_image_path($id) ?: get_attached_file($id);
            if (is_string($original) && $original !== '' && basename($original) === $file) {
                return (int) $id;
            }
        }

        return null;
    }

    private function isPending(mixed $value): bool
    {
        return $this->dryRun && is_string($value) && in_array($value, $this->pending, true);
    }

    /**
     * @param array<string,mixed> $field
     */
    private static function exportValue(array $field, mixed $value): mixed
    {
        if ($field['type'] === 'repeater') {
            if (!is_array($value) || !array_is_list($value)) {
                return $value;
            }

            return array_map(
                static fn (mixed $row): mixed => is_array($row) ? self::exportFields($field['fields'] ?? [], $row) : $row,
                $value,
            );
        }
        if (!in_array($field['type'], ['image', 'relation'], true)) {
            return $value;
        }

        $ref = static fn (int $id): string|int => $field['type'] === 'image' ? self::attachmentRef($id) : self::postRef($id);
        if (is_array($value)) {
            return array_map(static fn (mixed $id): mixed => is_int($id) ? $ref($id) : $id, $value);
        }

        return is_int($value) ? $ref($value) : $value;
    }

    /**
     * @param list<array{code:string,path:string,message:string}> $errors
     * @param-out list<array{code:string,path:string,message:string}> $errors
     */
    private function notFound(string $path, array &$errors): null
    {
        $errors[] = ['code' => 'not_found', 'path' => $path, 'message' => __('Reference not found.', 'nucleo')];

        return null;
    }

    /**
     * @param list<array{code:string,path:string,message:string}> $errors
     * @param-out list<array{code:string,path:string,message:string}> $errors
     */
    private function invalidValue(string $path, array &$errors): null
    {
        $errors[] = ['code' => 'invalid_value', 'path' => $path, 'message' => __('Invalid value.', 'nucleo')];

        return null;
    }
}
