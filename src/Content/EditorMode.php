<?php

declare(strict_types=1);

namespace Nucleo\Content;

defined('ABSPATH') || exit;

use Nucleo\Admin\Editor;
use Nucleo\Services;

/**
 * Modalità di una pagina Nucleo: documento Nucleo o editor WordPress.
 * Passare all'editor WordPress tiene il documento sospeso in _nucleo_doc_off; tornare a Nucleo lo riattiva.
 */
final class EditorMode
{
    private const META = '_nucleo_doc';
    private const OFF = '_nucleo_doc_off';
    private const RT = '_nucleo_rt';
    private const COPY = '_nucleo_wp_content';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
        add_action('rest_api_init', [self::class, 'guardContent']);
        add_action('add_meta_boxes', [self::class, 'addBox']);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueueBlockEditor']);
    }

    public static function registerRoutes(): void
    {
        register_rest_route('nucleo/v1', '/editor-mode', [
            'methods' => 'POST',
            'callback' => [self::class, 'switchRoute'],
            'permission_callback' => [self::class, 'canSwitch'],
            'args' => [
                'postId' => ['type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint'],
                'mode' => ['type' => 'string', 'required' => true, 'enum' => ['wp', 'nucleo']],
            ],
        ]);
    }

    /**
     * Capability edit_post e, con un token OAuth, gli stessi scope delle ability: write per il cambio di modalità,
     * publish se il post è online (il cambio riscrive il contenuto pubblico).
     */
    public static function canSwitch(\WP_REST_Request $request): bool|\WP_Error
    {
        $postId = (int) $request->get_param('postId');
        if (!current_user_can('edit_post', $postId)) {
            return false;
        }
        $scopes = \Nucleo\OAuth\CurrentToken::scopes();
        if ($scopes === null) {
            return true;
        }
        $status = get_post_status($postId);
        $missing = !in_array(\Nucleo\OAuth\Scopes::WRITE, $scopes, true) ? \Nucleo\OAuth\Scopes::WRITE
            : (in_array($status, ['publish', 'future', 'private'], true) && !in_array(\Nucleo\OAuth\Scopes::PUBLISH, $scopes, true) ? \Nucleo\OAuth\Scopes::PUBLISH : null);
        if ($missing === null) {
            return true;
        }

        /* translators: %s: name of the OAuth scope required by the ability. */
        return new \WP_Error('insufficient_scope', sprintf(__('Required scope: %s.', 'nucleo'), $missing), ['status' => 403, 'scope' => $missing]);
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function switchRoute(\WP_REST_Request $request): array|\WP_Error
    {
        $postId = (int) $request->get_param('postId');
        if ($request->get_param('mode') === 'wp') {
            $result = self::detach($postId);
        } elseif (self::hasInactive($postId)) {
            $result = self::attach($postId);
        } else {
            $result = true;
        }
        if (is_wp_error($result)) {
            return $result;
        }

        $nucleo = self::isActive($postId);

        return [
            'ok' => true,
            'mode' => $nucleo ? 'nucleo' : 'wp',
            // Verso Nucleo si apre sempre l'editor Nucleo: senza documento mostra la schermata "Inizia con Nucleo".
            'editUrl' => $request->get_param('mode') === 'nucleo'
                ? Editor::url($postId)
                : admin_url('post.php?post=' . $postId . '&action=edit'),
        ];
    }

    /**
     * Il salvataggio dall'editor a blocchi non cambia il contenuto di una pagina attiva: lo compila Nucleo.
     * Vale per ogni tipo di contenuto esposto alla REST.
     */
    public static function guardContent(): void
    {
        foreach (get_post_types(['show_in_rest' => true]) as $type) {
            add_filter('rest_pre_insert_' . $type, [self::class, 'keepStoredContent']);
        }
    }

    public static function keepStoredContent(\stdClass $prepared): \stdClass
    {
        $postId = (int) ($prepared->ID ?? 0);
        if (self::isActive($postId)) {
            $prepared->post_content = (string) get_post_field('post_content', $postId, 'raw');
        }

        return $prepared;
    }

    /** Riquadro Nucleo sulle pagine pubbliche con interfaccia: il pannello lo riempie via JS. */
    public static function addBox(string $postType): void
    {
        $object = get_post_type_object($postType);
        if ($object === null || !$object->public || !$object->show_ui) {
            return;
        }
        // Solo nell'editor classico: nell'editor a blocchi c'è il pannello "Nucleo" della barra laterale (page-settings.js).
        add_meta_box('nucleo-page', __('Nucleo', 'nucleo'), [self::class, 'renderBox'], $postType, 'side', 'high', ['__back_compat_meta_box' => true]);
    }

    public static function renderBox(\WP_Post $post): void
    {
        printf(
            '<p><a class="button button-primary" href="%s">%s</a></p>',
            esc_url(Editor::url((int) $post->ID)),
            esc_html__('Edit with Nucleo', 'nucleo')
        );
    }

    public static function enqueueBlockEditor(): void
    {
        $postId = (int) get_the_ID();
        $base = plugin_dir_url(dirname(__DIR__, 2) . '/nucleo.php');
        wp_enqueue_script(
            'nucleo-page-settings',
            $base . 'assets/admin/page-settings.js',
            ['wp-element', 'wp-components', 'wp-data', 'wp-plugins', 'wp-editor', 'wp-i18n', 'wp-api-fetch'],
            Editor::assetVersion('assets/admin/page-settings.js'),
            true
        );
        wp_set_script_translations('nucleo-page-settings', 'nucleo', dirname(__DIR__, 2) . '/languages');
        // Componenti nc-* (card, pulsanti) e, in modalità Nucleo, contenuto dell'editor nascosto dietro la scheda.
        \Nucleo\Admin\Assets::registerComponents($base);
        wp_enqueue_style('nucleo-block-editor', $base . 'assets/admin/block-editor.css', ['nucleo-admin'], Editor::assetVersion('assets/admin/block-editor.css'));
        wp_localize_script('nucleo-page-settings', 'nucleoBlockEditor', [
            'postId' => $postId,
            'mode' => self::isActive($postId) ? 'nucleo' : 'wp',
            'hasInactive' => self::hasInactive($postId),
            'nucleoUrl' => Editor::url($postId),
            'layouts' => self::layouts(),
        ]);
    }

    /**
     * Layout Nucleo pubblicati; l'area viene dal documento salvato, come in LayoutList.
     * @return list<array{id:int,title:string,area:string}>
     */
    public static function layouts(): array
    {
        $out = [];
        $posts = get_posts([
            'post_type' => 'nucleo_layout',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        foreach ($posts as $post) {
            $raw = get_post_meta($post->ID, self::META, true);
            $doc = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $meta = is_array($doc) && is_array($doc['meta'] ?? null) ? $doc['meta'] : [];
            $out[] = [
                'id' => (int) $post->ID,
                'title' => $post->post_title,
                'area' => is_string($meta['area'] ?? null) ? $meta['area'] : '',
            ];
        }

        return $out;
    }

    public static function isActive(int $postId): bool
    {
        return $postId > 0 && metadata_exists('post', $postId, self::META);
    }

    public static function hasInactive(int $postId): bool
    {
        return $postId > 0 && metadata_exists('post', $postId, self::OFF);
    }

    /**
     * Passa la pagina all'editor WordPress: il documento va in _nucleo_doc_off e il contenuto torna testo WordPress.
     * @return true|\WP_Error
     */
    public static function detach(int $postId): true|\WP_Error
    {
        if (!self::isActive($postId)) {
            return new \WP_Error('not_nucleo', __('The page is not edited with Nucleo.', 'nucleo'), ['status' => 409]);
        }

        $doc = get_post_meta($postId, self::META, true);
        $rt = get_post_meta($postId, self::RT, true);
        $content = self::wpContent($postId);

        update_post_meta($postId, self::OFF, wp_slash($doc));
        delete_post_meta($postId, self::META);
        delete_post_meta($postId, self::RT);

        $updated = wp_update_post(wp_slash(['ID' => $postId, 'post_content' => $content]), true);
        if (is_wp_error($updated)) {
            update_post_meta($postId, self::META, wp_slash($doc));
            delete_post_meta($postId, self::OFF);
            if ($rt !== '') {
                update_post_meta($postId, self::RT, wp_slash($rt));
            }

            return $updated;
        }

        return true;
    }

    /**
     * Torna alla modalità Nucleo: il testo WordPress corrente diventa la copia e il documento sospeso torna attivo.
     * @return true|\WP_Error
     */
    public static function attach(int $postId): true|\WP_Error
    {
        if (!self::hasInactive($postId)) {
            return new \WP_Error('no_inactive_document', __('The page has no Nucleo document to restore.', 'nucleo'), ['status' => 409]);
        }

        update_post_meta($postId, self::COPY, wp_slash((string) get_post_field('post_content', $postId, 'raw')));
        $doc = get_post_meta($postId, self::OFF, true);
        update_post_meta($postId, self::META, wp_slash($doc));
        delete_post_meta($postId, self::OFF);

        $result = Services::documents()->recompile($postId);
        if (!$result->ok) {
            update_post_meta($postId, self::OFF, wp_slash($doc));
            delete_post_meta($postId, self::META);

            return new \WP_Error('recompile_failed', $result->error['message'] ?? __('The Nucleo document could not be recompiled.', 'nucleo'), ['status' => 422]);
        }

        return true;
    }

    /** Testo WordPress da rimettere in pagina: la copia salvata, altrimenti l'HTML compilato dentro un blocco HTML. */
    private static function wpContent(int $postId): string
    {
        $copy = get_post_meta($postId, self::COPY, true);
        if (is_string($copy) && trim($copy) !== '') {
            return $copy;
        }

        return "<!-- wp:html -->\n" . (string) get_post_field('post_content', $postId, 'raw') . "\n<!-- /wp:html -->";
    }
}
