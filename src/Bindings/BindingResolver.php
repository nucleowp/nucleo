<?php

declare(strict_types=1);

namespace Nucleo\Bindings;

defined('ABSPATH') || exit;

use Nucleo\Content\FieldValues;
use Nucleo\Content\ModelRepository;
use WC_Product;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Legge il valore di un bind dal contesto WordPress. Restituisce null se il valore manca o non è scalare.
 * Per term il secondo parametro è il term_id interrogato; per user si legge l'utente corrente.
 */
final class BindingResolver
{
    public static function value(string $source, string $field, int $postId): ?string
    {
        return match ($source) {
            'post' => self::post($field, $postId),
            'site' => self::site($field),
            'author' => self::author($field, $postId),
            'metabox' => self::metabox($field, $postId),
            'jetengine' => self::jetengine($field, $postId),
            'acf' => self::acf($field, $postId),
            'nucleo' => self::nucleo($field, $postId),
            'term' => self::term($field, $postId),
            'user' => self::user($field),
            'woo' => self::woo($field, $postId),
            'query' => self::query($field),
            default => null,
        };
    }

    private static function post(string $field, int $postId): ?string
    {
        if (str_starts_with($field, 'meta:')) {
            return self::scalar(get_post_meta($postId, substr($field, 5), true));
        }

        return match ($field) {
            'title' => self::scalar(get_the_title($postId)),
            'excerpt' => self::scalar(get_the_excerpt($postId)),
            'date' => self::scalar(get_the_date('', $postId)),
            'url' => self::scalar(get_permalink($postId)),
            'image' => self::scalar(get_the_post_thumbnail_url($postId, 'large')),
            default => null,
        };
    }

    private static function site(string $field): ?string
    {
        return match ($field) {
            'name' => self::scalar(get_bloginfo('name')),
            'description' => self::scalar(get_bloginfo('description')),
            'url' => self::scalar(home_url('/')),
            default => null,
        };
    }

    private static function author(string $field, int $postId): ?string
    {
        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            return null;
        }
        $authorId = (int) $post->post_author;

        return match ($field) {
            'name' => self::scalar(get_the_author_meta('display_name', $authorId)),
            'bio' => self::scalar(get_the_author_meta('description', $authorId)),
            'url' => self::scalar(get_author_posts_url($authorId)),
            default => null,
        };
    }

    private static function metabox(string $field, int $postId): ?string
    {
        if (function_exists('rwmb_meta')) {
            return self::scalar(rwmb_meta($field, [], $postId));
        }

        return self::scalar(get_post_meta($postId, $field, true));
    }

    /** JetEngine salva i campi come meta del post: si legge la chiave così com'è. */
    private static function jetengine(string $field, int $postId): ?string
    {
        return self::scalar(get_post_meta($postId, $field, true));
    }

    private static function acf(string $field, int $postId): ?string
    {
        if (!function_exists('get_field')) {
            return self::scalar(get_post_meta($postId, $field, true));
        }
        $value = get_field($field, $postId);
        if (is_array($value) && isset($value['url'])) {
            return self::scalar($value['url']);
        }

        return self::scalar($value);
    }

    private static function term(string $field, int $termId): ?string
    {
        $term = get_term($termId);
        if (!$term instanceof WP_Term) {
            return null;
        }

        return match ($field) {
            'name' => self::scalar($term->name),
            'description' => self::scalar($term->description),
            'url' => self::scalar(get_term_link($term)),
            'slug' => self::scalar($term->slug),
            'count' => (string) $term->count,
            default => self::termField($term, $field),
        };
    }

    /** Campo del modello sulla tassonomia del termine: null se non esiste o non ha un valore salvato. */
    private static function termField(WP_Term $term, string $field): ?string
    {
        foreach (FieldValues::fieldsFor(['taxonomy' => $term->taxonomy]) as $def) {
            if (($def['key'] ?? null) !== $field) {
                continue;
            }
            if (!metadata_exists('term', $term->term_id, $field)) {
                return null;
            }

            // Il modello è già validato: ogni campo ha key, type e label.
            /** @var array{key: string, type: string, label: string, options?: list<array{value: string, label: string}>, fields?: list<array{key: string, type: string, label: string, options?: list<array{value: string, label: string}>}>} $def */
            return self::nucleoValue($def, get_term_meta($term->term_id, $field, true));
        }

        return null;
    }

    private static function user(string $field): ?string
    {
        $user = wp_get_current_user();
        if ($user->ID === 0) {
            return null;
        }

        return match ($field) {
            'name' => self::scalar($user->display_name),
            default => null,
        };
    }

    /** Campi del prodotto WooCommerce del post: null senza WooCommerce o se il post non è un prodotto. */
    private static function woo(string $field, int $postId): ?string
    {
        if (!function_exists('wc_get_product')) {
            return null;
        }
        $product = wc_get_product($postId);
        if (!$product instanceof WC_Product) {
            return null;
        }

        return match ($field) {
            'price' => self::wooPrice($product->get_price()),
            'regular_price' => self::wooPrice($product->get_regular_price()),
            'sale_price' => self::wooPrice($product->get_sale_price()),
            'sku' => self::scalar($product->get_sku()),
            'stock_status' => self::scalar($product->get_stock_status()),
            'rating' => self::scalar($product->get_average_rating()),
            default => null,
        };
    }

    /** Prezzo formattato con wc_price, senza markup HTML; il prezzo vuoto o non numerico non ha testo. */
    private static function wooPrice(string $value): ?string
    {
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return html_entity_decode(wp_strip_all_tags(wc_price((float) $value)), ENT_QUOTES, 'UTF-8');
    }

    /** Query principale della richiesta: ricerca, risultati totali e pagina corrente. */
    private static function query(string $field): ?string
    {
        return match ($field) {
            'search' => self::scalar(get_search_query(false)),
            'found' => self::foundPosts(),
            'page' => (string) max(1, (int) get_query_var('paged'), (int) get_query_var('page')),
            default => null,
        };
    }

    private static function foundPosts(): ?string
    {
        global $wp_query;
        if (!$wp_query instanceof WP_Query) {
            return null;
        }

        return (string) (int) $wp_query->found_posts;
    }

    /** Campo del modello dei contenuti: "chiave" oppure "ripetitore.indice.sottocampo" (indice da 0). */
    private static function nucleo(string $field, int $postId): ?string
    {
        $postType = get_post_type($postId);
        if ($postType === false) {
            return null;
        }

        $parts = explode('.', $field);
        $def = self::modelField($postType, $parts[0]);
        if ($def === null || !metadata_exists('post', $postId, $parts[0])) {
            return null;
        }
        $raw = get_post_meta($postId, $parts[0], true);

        if (count($parts) === 1) {
            return self::nucleoValue($def, $raw);
        }
        if (count($parts) !== 3 || $def['type'] !== 'repeater' || !ctype_digit($parts[1]) || !is_array($raw)) {
            return null;
        }

        $row = $raw[(int) $parts[1]] ?? null;
        $sub = self::subField($def, $parts[2]);
        if ($sub === null || !is_array($row) || !array_key_exists($parts[2], $row)) {
            return null;
        }

        return self::nucleoValue($sub, $row[$parts[2]]);
    }

    /**
     * Righe di un ripetitore del modello: ogni riga è sottocampo → testo, come nucleoValue. Null se il campo non è
     * un ripetitore del tipo del post; [] se il campo non ha righe.
     *
     * @return list<array<string,string>>|null
     */
    public static function rows(int $postId, string $field): ?array
    {
        $postType = get_post_type($postId);
        if ($postType === false) {
            return null;
        }

        $def = self::modelField($postType, $field);
        if ($def === null || $def['type'] !== 'repeater') {
            return null;
        }

        $raw = get_post_meta($postId, $field, true);
        if (!is_array($raw)) {
            return [];
        }
        self::primeRows($def, $raw);

        $rows = [];
        foreach (array_values($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = [];
            foreach ($def['fields'] ?? [] as $sub) {
                $cells[$sub['key']] = self::nucleoValue($sub, $row[$sub['key']] ?? null) ?? '';
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * @return array{key: string, type: string, label: string, options?: list<array{value: string, label: string}>, fields?: list<array{key: string, type: string, label: string, options?: list<array{value: string, label: string}>}>}|null
     */
    private static function modelField(string $postType, string $key): ?array
    {
        foreach (ModelRepository::model()['fieldGroups'] as $group) {
            if (($group['location']['postType'] ?? null) !== $postType) {
                continue;
            }
            foreach ($group['fields'] as $field) {
                if ($field['key'] === $key) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * @param array{key: string, type: string, label: string, options?: list<array{value: string, label: string}>, fields?: list<array{key: string, type: string, label: string, options?: list<array{value: string, label: string}>}>} $def
     * @return array{key: string, type: string, label: string, options?: list<array{value: string, label: string}>}|null
     */
    private static function subField(array $def, string $key): ?array
    {
        foreach ($def['fields'] ?? [] as $sub) {
            if ($sub['key'] === $key) {
                return $sub;
            }
        }

        return null;
    }

    /**
     * Testo di un campo del modello. Il ripetitore intero non ha testo: si legge per sottocampo.
     *
     * @param array{key: string, type: string, label: string, options?: list<array{value: string, label: string}>, fields?: list<array{key: string, type: string, label: string, options?: list<array{value: string, label: string}>}>} $def
     */
    private static function nucleoValue(array $def, mixed $raw): ?string
    {
        return match ($def['type']) {
            'boolean' => $raw ? '1' : '',
            'image' => is_numeric($raw) ? self::scalar(wp_get_attachment_url((int) $raw)) : null,
            'relation' => self::relationTitle(is_array($raw) ? (array_values($raw)[0] ?? null) : $raw),
            'select' => self::selectLabels($def, $raw),
            'repeater' => null,
            default => self::scalar($raw),
        };
    }

    /**
     * Numero di righe di un ripetitore senza formattare i valori; null se il campo non è un ripetitore del tipo del post.
     */
    public static function rowCount(int $postId, string $field): ?int
    {
        $postType = get_post_type($postId);
        $def = $postType === false ? null : self::modelField($postType, $field);
        if ($def === null || $def['type'] !== 'repeater') {
            return null;
        }
        $raw = get_post_meta($postId, $field, true);

        return is_array($raw) ? count(array_filter($raw, 'is_array')) : 0;
    }

    /**
     * Carica in una volta i post citati dalle righe (immagini e relazioni) con le loro meta: senza, ogni cella fa una query.
     * @param array{key: string, type: string, label: string, fields?: list<array{key: string, type: string, label: string}>} $def
     * @param array<mixed> $raw
     */
    private static function primeRows(array $def, array $raw): void
    {
        $ids = [];
        foreach ($def['fields'] ?? [] as $sub) {
            if ($sub['type'] !== 'image' && $sub['type'] !== 'relation') {
                continue;
            }
            foreach ($raw as $row) {
                $value = is_array($row) ? ($row[$sub['key']] ?? null) : null;
                foreach (is_array($value) ? $value : [$value] as $id) {
                    if (is_numeric($id) && (int) $id > 0) {
                        $ids[] = (int) $id;
                    }
                }
            }
        }
        if ($ids !== []) {
            _prime_post_caches(array_values(array_unique($ids)), false, true);
        }
    }

    /** Titolo del primo contenuto collegato, solo se pubblicato: bozze e post privati non escono nel testo. */
    private static function relationTitle(mixed $id): ?string
    {
        if (!is_numeric($id) || (int) $id <= 0 || get_post_status((int) $id) !== 'publish') {
            return null;
        }
        $title = get_the_title((int) $id);

        return $title === '' ? null : $title;
    }

    /**
     * Etichette delle opzioni scelte, unite con la virgola se il select è multiplo.
     *
     * @param array{key: string, type: string, label: string, options?: list<array{value: string, label: string}>, fields?: list<array{key: string, type: string, label: string, options?: list<array{value: string, label: string}>}>} $def
     */
    private static function selectLabels(array $def, mixed $raw): ?string
    {
        $labels = [];
        foreach (is_array($raw) ? $raw : [$raw] as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            foreach ($def['options'] ?? [] as $option) {
                if ((string) $option['value'] === (string) $value) {
                    $labels[] = (string) $option['label'];
                    break;
                }
            }
        }

        return $labels === [] ? null : implode(', ', $labels);
    }

    /** Solo stringhe e numeri sono valori; array, oggetti, false e null non si scrivono nel testo. */
    private static function scalar(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
