<?php

declare(strict_types=1);

namespace Nucleo\Admin;

use Nucleo\Abilities\Preview;
use Nucleo\Css\PropertyMap;
use Nucleo\Services;

/**
 * Editor backend di Nucleo: disattiva Gutenberg solo per i post gestiti da Nucleo
 * (meta _nucleo_doc) e carica l'app di modifica in una pagina nascosta dal menu.
 */
final class Editor
{
    private const SLUG = 'nucleo-editor';
    private const HOOK = 'toplevel_page_nucleo-editor';
    private const META = '_nucleo_doc';

    public static function register(): void
    {
        add_filter('use_block_editor_for_post', [self::class, 'useBlockEditor'], 10, 2);
        add_filter('get_edit_post_link', [self::class, 'editLink'], 10, 3);
        add_action('admin_menu', [self::class, 'addPage']);
        add_action('current_screen', [self::class, 'setTitle']);
        add_filter('admin_body_class', [self::class, 'foldMenu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('rest_api_init', [self::class, 'registerRoutes']);
        add_action('load-post.php', [self::class, 'redirectPostScreen']);
    }

    /** Route dell'anteprima: compila un documento senza salvarlo. */
    public static function registerRoutes(): void
    {
        register_rest_route('nucleo/v1', '/preview', [
            'methods' => 'POST',
            'callback' => [self::class, 'previewRoute'],
            // Con postId l'anteprima usa il contesto del post: serve il permesso di modificarlo.
            'permission_callback' => static fn (\WP_REST_Request $request): bool => current_user_can('edit_posts')
                && ((int) $request->get_param('postId') === 0 || current_user_can('edit_post', (int) $request->get_param('postId'))),
            'args' => [
                'document' => ['required' => true, 'type' => 'object'],
                'postId' => ['type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function previewRoute(\WP_REST_Request $request): array|\WP_Error
    {
        $doc = $request->get_param('document');
        if (!is_array($doc)) {
            return new \WP_Error('invalid_input', __('The "document" field must be an object.', 'nucleo'), ['status' => 400]);
        }

        $result = Preview::render($doc, true, (int) $request->get_param('postId'));
        if (!$result['ok']) {
            return $result;
        }
        return $result + self::previewAssets();
    }

    /**
     * Stessi stili del sito per le anteprime: bundle dei blocchi, CSS di base del tema e token.
     * @return array{styles:list<string>,tokensCss:string}
     */
    public static function previewAssets(): array
    {
        $styles = [\Nucleo\Compiler\BlockBundle::url()];
        if (is_file(get_template_directory() . '/base.css')) {
            $styles[] = get_template_directory_uri() . '/base.css';
        }
        $tokens = \Nucleo\Services::store()->get('tokens') ?? [];

        return ['styles' => $styles, 'tokensCss' => is_string($tokens['css'] ?? null) ? $tokens['css'] : ''];
    }

    /**
     * URL dell'editor Nucleo per layout e sezioni globali Nucleo; null per il resto.
     * Le pagine restano su post.php, dove l'utente modifica i campi personalizzati.
     */
    public static function editorRedirect(int $postId): ?string
    {
        $types = ['nucleo_layout', 'nucleo_section'];

        return self::isNucleo($postId) && in_array(get_post_type($postId), $types, true) ? self::url($postId) : null;
    }

    /** post.php?action=edit su un layout o una sezione mostra solo il titolo: si apre l'editor Nucleo. */
    public static function redirectPostScreen(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- legge l'azione della schermata post.php per un redirect, senza modificare dati.
        $action = isset($_GET['action']) && is_scalar($_GET['action']) ? sanitize_key(wp_unslash((string) $_GET['action'])) : '';
        if ($action !== 'edit') {
            return;
        }
        $target = self::editorRedirect(self::currentPost());
        if ($target !== null) {
            wp_safe_redirect($target);
            exit;
        }
        self::preparePostScreen(self::currentPost());
    }

    /**
     * Pagina Nucleo su post.php: restano titolo, campi personalizzati e pubblicazione.
     * L'editor classico sparisce: il contenuto è HTML compilato e una modifica lì andrebbe persa.
     */
    public static function preparePostScreen(int $postId): void
    {
        // Con l'editor a blocchi la scheda Nucleo (EditorMode) prende il posto del contenuto.
        if (!self::isNucleo($postId) || use_block_editor_for_post($postId)) {
            return;
        }
        remove_post_type_support((string) get_post_type($postId), 'editor');
        add_action('edit_form_after_title', [self::class, 'documentNotice']);
    }

    public static function documentNotice(\WP_Post $post): void
    {
        printf(
            '<div class="notice nucleo-notice nucleo-notice--info"><p>%s <a class="button button-primary" href="%s">%s</a></p></div>',
            esc_html__('The content of this page is edited in the Nucleo editor. The title, custom fields and publishing remain here.', 'nucleo'),
            esc_url(self::url($post->ID)),
            esc_html__('Open the Nucleo editor', 'nucleo')
        );
    }

    public static function url(int $postId): string
    {
        return admin_url('admin.php?page=' . self::SLUG . '&post=' . $postId);
    }

    /** @param \WP_Post|int $post */
    public static function useBlockEditor(bool $useBlockEditor, $post): bool
    {
        $id = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;

        // Layout e sezioni globali aprono sempre l'editor Nucleo; le pagine Nucleo mostrano la scheda Nucleo nell'editor a blocchi.
        return self::editorRedirect($id) !== null ? false : $useBlockEditor;
    }

    public static function editLink(string $url, int $postId, string $context): string
    {
        // Redirect dopo il salvataggio da post.php (campi personalizzati): resta su post.php.
        if ($context === 'url' && did_action('save_post') > 0 && self::editorRedirect($postId) === null) {
            return $url;
        }

        return self::isNucleo($postId) ? self::url($postId) : $url;
    }

    /**
     * Titoli e descrizioni dei blocchi nella lingua dell'utente. Il catalogo MCP resta in inglese per gli agenti:
     * la traduzione serve solo all'editor.
     *
     * @return array<string,array{title:string,description:string}>
     */
    public static function blockTexts(): array
    {
        $out = [];
        foreach (Services::registry()->all() as $manifest) {
            $out[$manifest->name] = [
                // phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.NonSingularStringLiteralText -- testi dei manifest, estratti nel catalogo da bin/i18n.
                'title' => translate($manifest->title, 'nucleo'),
                // phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.NonSingularStringLiteralText -- come sopra.
                'description' => translate($manifest->description, 'nucleo'),
            ];
        }

        return $out;
    }

    /** Versione dell'asset: versione del plugin più data del file, così il browser non tiene una copia vecchia. */
    public static function assetVersion(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $mtime = is_file($path) ? filemtime($path) : false;

        return NUCLEO_VERSION . ($mtime === false ? '' : '.' . $mtime);
    }

    /** URL pubblico del documento; vuoto per layout e sezioni, che non hanno una pagina propria. */
    public static function permalinkFor(int $postId): string
    {
        if (in_array(get_post_type($postId), ['nucleo_layout', 'nucleo_section'], true)) {
            return '';
        }

        return (string) get_permalink($postId);
    }

    /** Pagina fuori dal menu: WordPress non ne trova il titolo e admin-header passerebbe null a strip_tags. */
    public static function setTitle(): void
    {
        if (($GLOBALS['plugin_page'] ?? null) === self::SLUG) {
            $GLOBALS['title'] = __('Nucleo editor', 'nucleo');
        }
    }

    /** Nell'editor il menu laterale di WordPress parte compresso: l'anteprima ha più spazio. */
    public static function foldMenu(string $classes): string
    {
        if (($GLOBALS['plugin_page'] ?? null) !== self::SLUG || str_contains(' ' . $classes . ' ', ' folded ')) {
            return $classes;
        }

        return $classes . ' folded';
    }

    public static function addPage(): void
    {
        add_menu_page(
            __('Nucleo editor', 'nucleo'),
            __('Nucleo editor', 'nucleo'),
            'edit_posts',
            self::SLUG,
            [self::class, 'render']
        );
        // La pagina resta raggiungibile dai link del plugin, ma non compare nel menu.
        remove_menu_page(self::SLUG);
    }

    /**
     * Ability nucleo/* in sola lettura: l'API le esegue solo in GET.
     * @return list<string>
     */
    public static function readonlyAbilities(): array
    {
        $out = [];
        foreach (wp_get_abilities() as $ability) {
            $name = $ability->get_name();
            if (str_starts_with($name, 'nucleo/') && ($ability->get_meta()['annotations']['readonly'] ?? false) === true) {
                $out[] = $name;
            }
        }

        return $out;
    }

    public static function render(): void
    {
        $postId = self::currentPost();
        if ($postId < 1 || !current_user_can('edit_post', $postId)) {
            wp_die(esc_html__('You do not have permission to edit this page.', 'nucleo'), '', ['response' => 403]);
        }
        // Sprite di pagina (gradienti) e dei blocchi: icone dello schema e dell'inserter.
        echo Icons::sprite() . Icons::blockSprite(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statici del plugin.
        printf('<div id="nucleo-editor" class="nucleo-admin" data-post="%s"></div>', esc_attr((string) $postId));
    }

    public static function enqueue(string $hook): void
    {
        if ($hook !== self::HOOK) {
            return;
        }
        $base = plugin_dir_url(dirname(__DIR__, 2) . '/nucleo.php');

        // tree.js: operazioni pure sull'albero (window.nucleoTree). labels.js: etichette per il cliente (window.nucleoLabels).
        // panels.js: pannelli laterali (window.nucleoPanels). inserter.js: catalogo per l'inserimento (window.nucleoInserter).
        // shortcuts.js: scorciatoie da tastiera (window.nucleoShortcuts). revisions.js: cassetto delle revisioni (window.nucleoRevisions).
        Assets::registerUi($base);
        wp_register_script('nucleo-editor-inline', $base . 'assets/editor/inline-edit.js', [], self::assetVersion('assets/editor/inline-edit.js'), true);
        wp_register_script('nucleo-editor-labels', $base . 'assets/editor/labels.js', [], self::assetVersion('assets/editor/labels.js'), true);
        wp_register_script(
            'nucleo-editor-panels',
            $base . 'assets/editor/panels.js',
            ['wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n'],
            self::assetVersion('assets/editor/panels.js'),
            true
        );
        // doc-diff.js: differenze tra revisioni (window.nucleoDocDiff), usato dalla scheda Attività AI.
        wp_register_script('nucleo-doc-diff', $base . 'assets/shared/doc-diff.js', [], self::assetVersion('assets/shared/doc-diff.js'), true);
        wp_register_script('nucleo-editor-panels-ai', $base . 'assets/editor/panels-ai.js', ['nucleo-editor-panels', 'nucleo-doc-diff'], self::assetVersion('assets/editor/panels-ai.js'), true);
        // page-settings.js: impostazioni tema della pagina, condivise con l'editor a blocchi. panels-page.js: scheda Impostazioni pagina.
        wp_register_script('nucleo-page-settings', $base . 'assets/admin/page-settings.js', ['wp-element', 'wp-components', 'wp-i18n'], self::assetVersion('assets/admin/page-settings.js'), true);
        wp_register_script('nucleo-editor-panels-page', $base . 'assets/editor/panels-page.js', ['nucleo-editor-panels', 'nucleo-page-settings', 'wp-api-fetch'], self::assetVersion('assets/editor/panels-page.js'), true);
        wp_register_script('nucleo-editor-panels-fields', $base . 'assets/editor/panels-fields.js', ['nucleo-editor-panels'], self::assetVersion('assets/editor/panels-fields.js'), true);
        wp_register_script(
            'nucleo-editor-inserter',
            $base . 'assets/editor/inserter.js',
            ['wp-element', 'wp-i18n'],
            self::assetVersion('assets/editor/inserter.js'),
            true
        );
        wp_register_script('nucleo-editor-mockup', $base . 'assets/editor/mockup.js', ['nucleo-editor-preview-size'], self::assetVersion('assets/editor/mockup.js'), true);
        wp_register_script('nucleo-editor-preview-size', $base . 'assets/editor/preview-size.js', [], self::assetVersion('assets/editor/preview-size.js'), true);
        wp_register_script('nucleo-editor-shortcuts', $base . 'assets/editor/shortcuts.js', [], self::assetVersion('assets/editor/shortcuts.js'), true);
        wp_register_script(
            'nucleo-editor-revisions',
            $base . 'assets/editor/revisions.js',
            ['wp-element', 'wp-components', 'wp-i18n'],
            self::assetVersion('assets/editor/revisions.js'),
            true
        );
        wp_register_script(
            'nucleo-editor',
            $base . 'assets/editor/editor.js',
            [
                'wp-element',
                'wp-components',
                'wp-api-fetch',
                'wp-i18n',
                'nucleo-editor-tree',
                'nucleo-ui',
                'nucleo-editor-inline',
                'nucleo-editor-labels',
                'nucleo-editor-panels',
                'nucleo-editor-panels-ai',
                'nucleo-editor-panels-fields',
                'nucleo-editor-panels-page',
                'nucleo-editor-inserter',
                'nucleo-editor-shortcuts',
                'nucleo-editor-preview-size',
                'nucleo-editor-mockup',
                'nucleo-editor-revisions',
            ],
            self::assetVersion('assets/editor/editor.js'),
            true
        );
        // Token del design system: stesso file dell'area admin, registrato qui se la pagina non l'ha già fatto.
        Assets::registerComponents($base);
        wp_register_style('nucleo-editor-mockup', $base . 'assets/editor/mockup.css', ['nucleo-admin-tokens'], self::assetVersion('assets/editor/mockup.css'));
        wp_register_style(
            'nucleo-editor',
            $base . 'assets/editor/editor.css',
            ['nucleo-admin', 'nucleo-editor-mockup'],
            self::assetVersion('assets/editor/editor.css')
        );
        $languages = dirname(__DIR__, 2) . '/languages';
        foreach (['nucleo-editor', 'nucleo-editor-labels', 'nucleo-editor-panels', 'nucleo-editor-panels-ai', 'nucleo-editor-panels-fields', 'nucleo-editor-panels-page', 'nucleo-page-settings', 'nucleo-editor-inserter', 'nucleo-editor-revisions', 'nucleo-doc-diff'] as $handle) {
            wp_set_script_translations($handle, 'nucleo', $languages);
        }
        wp_enqueue_script('nucleo-editor');
        wp_enqueue_style('nucleo-editor');
        wp_enqueue_media();

        $config = [
            'postId' => self::currentPost(),
            'title' => get_the_title(self::currentPost()),
            'permalink' => self::permalinkFor(self::currentPost()),
            'postType' => (string) get_post_type(self::currentPost()),
            // Scheda Campi: solo se il tipo di contenuto ha gruppi di campi nel modello.
            // Contenuto senza documento Nucleo: l'editor avvisa che il primo salvataggio sostituisce il testo esistente.
            'wpEditUrl' => admin_url('post.php?post=' . self::currentPost() . '&action=edit'),
            'hasContent' => trim((string) get_post_field('post_content', self::currentPost())) !== '',
            'hasFields' => \Nucleo\Content\FieldValues::fields('post', self::currentPost()) !== [],
            'previewBase' => home_url(),
            'breakpoints' => ['md' => 768, 'lg' => 1024, 'xl' => 1920],
            'styleKeys' => PropertyMap::keysByGroup(),
            'blockTexts' => self::blockTexts(),
            'readonly' => self::readonlyAbilities(),
            // Ruoli dell'utente e ruoli "solo testi": l'editor forza la vista testi se tutti i ruoli dell'utente sono in elenco.
            'roles' => array_values(wp_get_current_user()->roles),
            'textOnlyRoles' => self::textOnlyRoles(),
            'editorBase' => admin_url('admin.php?page=' . self::SLUG . '&post='),
        ] + self::pageConfig(self::currentPost());
        // Prima della scheda Campi, che legge hasFields al caricamento: è la prima dipendenza che lo usa.
        wp_add_inline_script(
            'nucleo-editor-panels-fields',
            'window.nucleoEditor = ' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP) . ';',
            'before'
        );
    }

    /**
     * Dati della scheda Impostazioni pagina (panels-page.js): endpoint REST, funzioni del tipo, tassonomie e layout.
     * @return array<string,mixed>
     */
    private static function pageConfig(int $postId): array
    {
        $type = get_post_type_object((string) get_post_type($postId));
        if ($type === null || !$type->show_in_rest) {
            return [];
        }
        $taxonomies = [];
        foreach (get_object_taxonomies($type->name, 'objects') as $tax) {
            if ($tax->show_ui && $tax->show_in_rest) {
                $taxonomies[] = [
                    'slug' => $tax->name,
                    'restBase' => is_string($tax->rest_base) && $tax->rest_base !== '' ? $tax->rest_base : $tax->name,
                    'label' => $tax->labels->name,
                    'hierarchical' => $tax->hierarchical,
                ];
            }
        }

        return [
            'restBase' => is_string($type->rest_base) && $type->rest_base !== '' ? $type->rest_base : $type->name,
            'supports' => [
                'excerpt' => post_type_supports($type->name, 'excerpt'),
                'thumbnail' => post_type_supports($type->name, 'thumbnail'),
                'pageAttributes' => post_type_supports($type->name, 'page-attributes'),
                'comments' => post_type_supports($type->name, 'comments'),
            ],
            'taxonomies' => $taxonomies,
            'layouts' => \Nucleo\Content\EditorMode::layouts(),
            'hasInactive' => \Nucleo\Content\EditorMode::hasInactive($postId),
        ];
    }

    /**
     * Ruoli per cui l'editor mostra solo i testi: editor.textOnlyRoles delle impostazioni (stessa lettura di SettingsGet).
     * @return list<string>
     */
    private static function textOnlyRoles(): array
    {
        $stored = get_option('nucleo_settings', []);
        $roles = is_array($stored) ? ($stored['editor']['textOnlyRoles'] ?? []) : [];

        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }

    private static function isNucleo(int $postId): bool
    {
        return $postId > 0 && metadata_exists('post', $postId, self::META);
    }

    private static function currentPost(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ID del post da aprire nell'editor; i permessi si verificano a parte.
        return isset($_GET['post']) && is_scalar($_GET['post']) ? absint(wp_unslash((string) $_GET['post'])) : 0;
    }
}
