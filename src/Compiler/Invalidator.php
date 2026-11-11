<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

defined('ABSPATH') || exit;

use Nucleo\Runtime\Collection\ContentVersion;
use WP_Post;

/**
 * Accoda la ricompilazione delle pagine che dipendono da una sezione, un menu, una query o il sito.
 * Le dipendenze sono salvate in _nucleo_dep dal DocumentService.
 */
final class Invalidator
{
    private const DEP_META = '_nucleo_dep';

    /** Pagine ricompilate subito dopo il salvataggio di una sezione globale. */
    private const SYNC_MAX = 10;

    /** Una scrittura di Nucleo ha accodato documenti con link: si ricompilano dopo che il documento è salvato. */
    private static bool $linksPending = false;

    /** Vero mentre onLanguageChanged() adegua i formati. */
    private static bool $adapting = false;

    /** @var array<string,true> formati scritti in questa richiesta da fuori (date_format, time_format) */
    private static array $touched = [];

    /** Idempotente: il controllo è sugli hook, così un nuovo register dopo un reset riaggancia tutto. */
    public static function register(): void
    {
        if (has_action('nucleo/document_saved', [self::class, 'onDocumentSaved']) !== false) {
            return;
        }

        add_action('nucleo/document_saved', [self::class, 'onDocumentSaved']);
        add_action('wp_update_nav_menu', [self::class, 'onMenuChanged']);
        add_action('wp_update_nav_menu_item', [self::class, 'onMenuChanged']);
        add_action('transition_post_status', [self::class, 'onTransition'], 10, 3);
        add_action('post_updated', [self::class, 'onPostUpdated'], 10, 3);
        add_action('profile_update', [self::class, 'onProfileUpdated']);
        // Le schede di nc/query leggono meta e termini dei post elencati.
        add_action('added_post_meta', [self::class, 'onPostMeta'], 10, 3);
        add_action('updated_post_meta', [self::class, 'onPostMeta'], 10, 3);
        add_action('deleted_post_meta', [self::class, 'onPostMeta'], 10, 3);
        add_action('set_object_terms', [self::class, 'onObjectTerms']);
        add_action('update_option_blogname', [self::class, 'onSiteChanged']);
        add_action('update_option_blogdescription', [self::class, 'onSiteChanged']);
        add_action('update_option_site_logo', [self::class, 'onSiteChanged']);
        add_action('customize_save_after', [self::class, 'onSiteChanged']);
        // Link per riferimento (page:<slug>, term:<tassonomia>/<slug>): vedi LinkRefs.
        add_action('deleted_post', [self::class, 'onPostDeleted']);
        add_action('created_term', [self::class, 'onTermChanged'], 10, 3);
        add_action('edited_term', [self::class, 'onTermChanged'], 10, 3);
        add_action('delete_term', [self::class, 'onTermChanged'], 10, 3);
        // Nuova struttura dei permalink: tutti gli URL dei link cambiano.
        add_action('update_option_permalink_structure', [RebuildQueue::class, 'enqueueAll']);
        // Lingua e formati di data e ora: date ed etichette compilate o in cache cambiano testo.
        add_action('update_option_WPLANG', [self::class, 'onLanguageChanged'], 10, 2);
        add_action('add_option_WPLANG', [self::class, 'onLanguageAdded'], 10, 2);
        add_action('update_option_date_format', [self::class, 'onFormatChanged'], 10, 3);
        add_action('update_option_time_format', [self::class, 'onFormatChanged'], 10, 3);
    }

    /**
     * Pagine da ricompilare e cache di schede e collezioni da svuotare: il testo delle date cambia.
     * Un formato scritto da fuori (Impostazioni > Generali) resta segnato: il cambio di lingua nella stessa richiesta non lo tocca.
     */
    public static function onFormatChanged(mixed $old = null, mixed $value = null, string $option = ''): void
    {
        if (self::$adapting) {
            return;
        }
        if ($option !== '') {
            self::$touched[$option] = true;
        }
        RebuildQueue::enqueueAll();
        ContentVersion::bump();
    }

    /** Dimentica i formati scritti in questa richiesta: per i test, che vivono in un solo processo. */
    public static function forgetTouchedFormats(): void
    {
        self::$touched = [];
    }

    public static function onLanguageAdded(mixed $option, mixed $value): void
    {
        self::onLanguageChanged('', $value);
    }

    /**
     * Nuova lingua del sito. WordPress fissa date_format e time_format all'installazione, nella lingua di allora:
     * se valgono ancora il formato predefinito della lingua precedente ("F j, Y" in inglese), prendono quello della
     * nuova lingua ("j F Y" in italiano). Un formato scelto a mano resta. Poi pagine e cache si rigenerano.
     */
    public static function onLanguageChanged(mixed $old, mixed $new): void
    {
        $from = is_string($old) && $old !== '' ? $old : 'en_US';
        $to = is_string($new) && $new !== '' ? $new : 'en_US';
        if ($from !== $to) {
            // Le scritture dei formati qui non ripetono ricompilazione e bump: li fa una volta onFormatChanged() sotto.
            self::$adapting = true;
            try {
                foreach (['date_format' => 'F j, Y', 'time_format' => 'g:i a'] as $option => $english) {
                    if (isset(self::$touched[$option])) {
                        continue;
                    }
                    $previous = self::translatedDefault($english, $from);
                    $next = self::translatedDefault($english, $to);
                    $current = get_option($option);
                    if ($next !== null && $current !== $next && in_array($current, [$english, $previous], true)) {
                        update_option($option, $next);
                    }
                }
            } finally {
                self::$adapting = false;
            }
        }
        self::onFormatChanged();
    }

    /** Formato predefinito di WordPress tradotto nella lingua indicata; null se la lingua non è installata. */
    private static function translatedDefault(string $format, string $locale): ?string
    {
        if ($locale === 'en_US') {
            return $format;
        }
        if (!in_array($locale, get_available_languages(), true)) {
            return null;
        }

        // Formato di WordPress, già tradotto nel catalogo di core (dominio default): lo si legge dal catalogo, il
        // plugin non traduce stringhe di altri domini. Come translate(): catalogo di core più il filtro gettext.
        return ContentLocale::run($locale, static fn (): string => (string) apply_filters(
            'gettext',
            get_translations_for_domain('default')->translate($format),
            $format,
            'default'
        ));
    }

    /** @return list<int> id dei post con la dipendenza indicata */
    public static function dependents(string $key): array
    {
        // Direct meta lookup: get_posts with post_type "any" skips the private Nucleo post types.
        // Pagine di 500 ordinate per id: nessun taglio silenzioso oltre il primo blocco.
        global $wpdb;
        $ids = [];
        $after = 0;
        do {
            $page = array_values(array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s AND post_id > %d ORDER BY post_id LIMIT 500",
                self::DEP_META,
                $key,
                $after,
            ))));
            $ids = array_merge($ids, $page);
            if ($page !== []) {
                $after = end($page);
            }
        } while (count($page) === 500);

        return $ids;
    }

    public static function onDocumentSaved(int $postId): void
    {
        if (self::$linksPending) {
            self::$linksPending = false;
            RebuildQueue::forget([$postId]);
            RebuildQueue::run(self::SYNC_MAX);
        }
        if (get_post_type($postId) !== 'nucleo_section') {
            return;
        }

        RebuildQueue::enqueue(self::sectionDependents($postId));
        // Le prime pagine si ricompilano nella stessa richiesta; le altre restano a WP-Cron.
        RebuildQueue::run(self::SYNC_MAX);
    }

    /**
     * Documenti che usano la sezione: per id, e per slug quelli che la citavano quando mancava.
     * @return list<int>
     */
    public static function sectionDependents(int $postId): array
    {
        $slug = preg_replace('/__trashed$/', '', (string) get_post_field('post_name', $postId));
        $ids = self::dependents('section:' . $postId);
        if ($slug !== '' && $slug !== null) {
            $ids = array_merge($ids, self::dependents('section-slug:' . $slug));
        }

        return array_values(array_diff(array_unique($ids), [$postId]));
    }

    /** Slug di una sezione cambiato: si ricompilano i documenti che citavano il vecchio slug e quelli che citano il nuovo. */
    public static function onPostUpdated(int $postId, WP_Post $after, WP_Post $before): void
    {
        // Slug o genitore cambiato: i link che puntano al contenuto cambiano URL o restano senza bersaglio.
        if (($after->post_name !== $before->post_name || $after->post_parent !== $before->post_parent) && in_array('publish', [$after->post_status, $before->post_status], true)) {
            self::rebuildLinks($after);
        }
        // Titolo, estratto, data o autore cambiati: il post si ricompila se una sua dipendenza li legge.
        if (
            !DocumentService::writing()
            && ($after->post_title !== $before->post_title || $after->post_excerpt !== $before->post_excerpt || $after->post_date !== $before->post_date || $after->post_author !== $before->post_author)
            && self::readsPost($postId)
        ) {
            RebuildQueue::enqueue([$postId]);
            RebuildQueue::run(self::SYNC_MAX);
        }
        if ($after->post_type !== 'nucleo_section' || $after->post_name === $before->post_name) {
            return;
        }

        $ids = self::sectionDependents($postId);
        if ($before->post_name !== '') {
            $ids = array_merge($ids, self::dependents('section-slug:' . preg_replace('/__trashed$/', '', $before->post_name)));
        }
        RebuildQueue::enqueue(array_values(array_diff($ids, [$postId])));
        RebuildQueue::run(self::SYNC_MAX);
    }

    /** Vero se il post ha una dipendenza che legge i suoi dati o quelli dell'autore (bind:post: o bind:author:). */
    private static function readsPost(int $postId): bool
    {
        foreach (get_post_meta($postId, self::DEP_META, false) as $dep) {
            if (is_string($dep) && (str_starts_with($dep, 'bind:post:') || str_starts_with($dep, 'bind:author:'))) {
                return true;
            }
        }

        return false;
    }

    /** Profilo utente salvato: le pagine dei suoi post con un bind all'autore leggono nome e campi dell'utente. */
    public static function onProfileUpdated(int $userId): void
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value LIKE %s AND p.post_author = %d",
            self::DEP_META,
            $wpdb->esc_like('bind:author:') . '%',
            $userId,
        ));
        RebuildQueue::enqueue(array_values(array_map('intval', $ids)));
    }

    public static function onMenuChanged(int $menuId): void
    {
        RebuildQueue::enqueue(self::dependents('menu:' . $menuId));
    }

    public static function onTransition(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        // Pubblicazione, cestino o ritiro di un contenuto: anche durante una scrittura di Nucleo (doc-publish).
        if ($newStatus !== $oldStatus && in_array('publish', [$newStatus, $oldStatus], true)) {
            self::rebuildLinks($post);
        }
        if (DocumentService::writing()) {
            return;
        }
        // Sezione cestinata o ripristinata: le pagine che la usano la tolgono o la rimettono.
        if ($post->post_type === 'nucleo_section' && $newStatus !== $oldStatus && in_array('trash', [$newStatus, $oldStatus], true)) {
            RebuildQueue::enqueue(self::sectionDependents($post->ID));
            RebuildQueue::run(self::SYNC_MAX);

            return;
        }
        if (in_array($post->post_type, ['nucleo_layout', 'nucleo_section', 'revision'], true)) {
            return;
        }
        if (wp_is_post_autosave($post) !== false || wp_is_post_revision($post) !== false) {
            return;
        }
        // Pubblicazione, rimozione dal pubblicato, o post che resta pubblicato.
        if ($newStatus !== 'publish' && $oldStatus !== 'publish') {
            return;
        }

        RebuildQueue::enqueue(self::dependents('query:' . $post->post_type));
    }

    /** Meta di un post pubblicato: chiavi senza "_" e l'immagine in evidenza. */
    public static function onPostMeta(mixed $metaId, int $postId, string $metaKey): void
    {
        if (str_starts_with($metaKey, '_') && $metaKey !== '_thumbnail_id') {
            return;
        }
        self::onObjectTerms($postId);
    }

    public static function onObjectTerms(int $objectId): void
    {
        if (DocumentService::writing()) {
            return;
        }
        $post = get_post($objectId);
        if (!$post instanceof WP_Post || $post->post_status !== 'publish' || in_array($post->post_type, ['nucleo_layout', 'nucleo_section'], true)) {
            return;
        }

        RebuildQueue::enqueue(self::dependents('query:' . $post->post_type));
    }

    public static function onPostDeleted(int $postId): void
    {
        RebuildQueue::enqueue(self::dependents('link:post:' . $postId));
    }

    public static function onTermChanged(int $termId, int $ttId = 0, string $taxonomy = ''): void
    {
        // I termini figli cambiano URL con il genitore.
        $children = $taxonomy !== '' ? get_term_children($termId, $taxonomy) : [];
        $ids = [];
        foreach (array_merge([$termId], is_array($children) ? $children : []) as $id) {
            $ids = array_merge($ids, self::dependents('link:term:' . (int) $id));
        }
        $term = $taxonomy !== '' ? get_term($termId, $taxonomy) : null;
        if ($term instanceof \WP_Term) {
            $ids = array_merge($ids, self::dependents('link-slug:term:' . $taxonomy . '/' . $term->slug));
        }
        RebuildQueue::enqueue(array_values(array_unique($ids)));
    }

    /**
     * Documenti con un link al post o ai suoi discendenti: per id quelli che li raggiungevano, per slug quelli che li cercavano.
     * Solo coda: durante una scrittura di Nucleo la ricompilazione parte dopo il salvataggio (onDocumentSaved),
     * altrimenti la esegue WP-Cron, senza utente corrente.
     */
    private static function rebuildLinks(WP_Post $post): void
    {
        if (in_array($post->post_type, ['nucleo_layout', 'nucleo_section', 'revision', 'nav_menu_item'], true) || wp_is_post_revision($post) !== false) {
            return;
        }
        $posts = [$post];
        if (is_post_type_hierarchical($post->post_type)) {
            $posts = array_merge($posts, get_pages(['child_of' => $post->ID, 'post_type' => $post->post_type, 'post_status' => ['publish', 'draft', 'private', 'pending', 'future']]) ?: []);
        }
        $ids = [];
        foreach ($posts as $item) {
            $ids = array_merge($ids, self::dependents('link:post:' . $item->ID));
            $slug = (string) preg_replace('/__trashed$/', '', is_post_type_hierarchical($item->post_type) ? (string) get_page_uri($item) : $item->post_name);
            if ($slug !== '') {
                $ids = array_merge($ids, self::dependents('link-slug:' . $item->post_type . ':' . $slug));
            }
        }
        $ids = array_values(array_diff(array_unique($ids), [$post->ID]));
        if ($ids === []) {
            return;
        }
        RebuildQueue::enqueue($ids);
        self::$linksPending = self::$linksPending || DocumentService::writing();
    }

    public static function onSiteChanged(): void
    {
        $ids = self::dependents('site');
        foreach (['bind:site:name', 'bind:site:description', 'bind:site:logo', 'bind:site:url'] as $dep) {
            $ids = array_merge($ids, self::dependents($dep));
        }
        RebuildQueue::enqueue(array_values(array_unique($ids)));
    }
}
