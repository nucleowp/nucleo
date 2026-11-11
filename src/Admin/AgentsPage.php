<?php

declare(strict_types=1);

namespace Nucleo\Admin;

defined('ABSPATH') || exit;

use Nucleo\Mcp\RestController;
use Nucleo\OAuth\Server;

/**
 * Pannello "Agenti AI" in Strumenti: client OAuth collegati, aggiunta manuale (pubblica o confidenziale),
 * revoca dei loro token, eliminazione, interruttore della registrazione dinamica, domini ammessi,
 * URL del server MCP e regola .htaccess per l'header Authorization.
 */
final class AgentsPage
{
    private const SLUG = 'nucleo-agents';
    private const ADD_ACTION = 'nucleo_oauth_add_client';
    private const CONFIDENTIAL_ACTION = 'nucleo_oauth_add_confidential';
    private const DELETE_ACTION = 'nucleo_oauth_delete_client';
    private const REVOKE_ACTION = 'nucleo_oauth_revoke_client';
    private const DCR_ACTION = 'nucleo_oauth_toggle_dcr';
    private const DOMAINS_ACTION = 'nucleo_oauth_save_domains';
    private const HTACCESS_ACTION = 'nucleo_oauth_write_htaccess';
    private const DB_OPTION = 'nucleo_oauth_db';
    private const DB_VERSION = '2';
    private const DCR_OPTION = 'nucleo_oauth_dcr';
    private const DOMAINS_OPTION = 'nucleo_oauth_domains';
    private const REVOKED_ADMIN = 2;
    private const NAME_MAX = 200;
    /** Il secret confidenziale viene mostrato una volta sola: il transient dura 60 secondi, poi scade. */
    private const SECRET_TRANSIENT = 'nucleo_oauth_secret_';
    private const SECRET_TTL = 60;

    public static function register(): void
    {
        add_action('admin_post_' . self::ADD_ACTION, [self::class, 'addFromPost']);
        add_action('admin_post_' . self::CONFIDENTIAL_ACTION, [self::class, 'addConfidentialFromPost']);
        add_action('admin_post_' . self::DELETE_ACTION, [self::class, 'deleteClient']);
        add_action('admin_post_' . self::REVOKE_ACTION, [self::class, 'revokeClient']);
        add_action('admin_post_' . self::DCR_ACTION, [self::class, 'toggleDcr']);
        add_action('admin_post_' . self::DOMAINS_ACTION, [self::class, 'saveDomainsFromPost']);
        add_action('admin_post_' . self::HTACCESS_ACTION, [self::class, 'writeHtaccessFromPost']);
    }

    /**
     * Registra un client pre-registrato (kind 'pre') e ne restituisce il client_id.
     * Il nome è obbligatorio; il redirect segue le regole di Server::registerClient.
     * Codici di errore: 'missing_name', 'name_too_long', 'invalid_redirect', 'save_failed'.
     */
    public static function addClient(string $name, string $redirectUri): string|\WP_Error
    {
        $checked = self::checkName($name);
        if ($checked instanceof \WP_Error) {
            return $checked;
        }

        self::ensureSchema();
        try {
            $client = (new Server())->registerClient(
                ['client_name' => $checked, 'redirect_uris' => [$redirectUri]],
                'pre'
            );
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_redirect', $e->getMessage());
        } catch (\RuntimeException $e) {
            return new \WP_Error('save_failed', $e->getMessage());
        }

        return $client['client_id'];
    }

    /**
     * Registra un client confidenziale: nome e redirect (uno per riga). Il secret lo genera il plugin,
     * salva solo l'hash e lo restituisce una volta sola. Codici di errore come addClient.
     *
     * @return array{client_id:string,secret:string}|\WP_Error
     */
    public static function addConfidentialClient(string $name, string $redirectUris): array|\WP_Error
    {
        $checked = self::checkName($name);
        if ($checked instanceof \WP_Error) {
            return $checked;
        }
        $uris = self::lines($redirectUris);
        if ($uris === []) {
            return new \WP_Error('invalid_redirect', __('Enter at least one redirect URL.', 'nucleo'));
        }

        self::ensureSchema();
        $secret = wp_generate_password(40, false);
        try {
            // L'hash entra nella stessa insert del client: in caso di errore non resta un client pubblico.
            $client = (new Server())->registerClient([
                'client_name' => $checked,
                'redirect_uris' => $uris,
                'secret_hash' => wp_hash_password($secret),
            ], 'pre');
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_redirect', $e->getMessage());
        } catch (\RuntimeException $e) {
            return new \WP_Error('save_failed', $e->getMessage());
        }

        return ['client_id' => $client['client_id'], 'secret' => $secret];
    }

    /** Porta a revoked = 2 i token ancora attivi del client; il client resta registrato. */
    public static function revokeTokens(string $clientId): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET revoked = %d WHERE client_id = %s AND revoked = 0',
            self::table('tokens'),
            self::REVOKED_ADMIN,
            $clientId
        ));
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'nucleo'), '', ['response' => 403]);
        }
        self::ensureSchema();

        global $wpdb;
        $clients = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT client_id, name, kind, created, last_used, secret_hash IS NOT NULL AS confidential FROM %i ORDER BY created DESC',
                self::table('clients')
            ),
            ARRAY_A
        );
        $active = $wpdb->get_results($wpdb->prepare(
            'SELECT client_id, COUNT(*) AS n, GROUP_CONCAT(DISTINCT scopes SEPARATOR \' \') AS scopes FROM %i WHERE kind = %s AND revoked = 0 AND expires > %d GROUP BY client_id',
            self::table('tokens'),
            'access',
            time()
        ), ARRAY_A);
        // Utente che ha autorizzato ciascun client: quello del codice più recente.
        $authorRows = $wpdb->get_results($wpdb->prepare(
            'SELECT t.client_id, t.user_id FROM %i t INNER JOIN (SELECT client_id, MAX(id) AS max_id FROM %i WHERE kind = %s GROUP BY client_id) m ON t.id = m.max_id',
            self::table('tokens'),
            self::table('tokens'),
            'code'
        ), ARRAY_A);

        $counts = [];
        $scopes = [];
        foreach (is_array($active) ? $active : [] as $row) {
            $id = (string) $row['client_id'];
            $counts[$id] = (int) $row['n'];
            $scopes[$id] = implode(' ', array_values(array_unique(array_filter(explode(' ', (string) $row['scopes'])))));
        }
        $authors = [];
        foreach (is_array($authorRows) ? $authorRows : [] as $row) {
            $authors[(string) $row['client_id']] = (int) $row['user_id'];
        }
        $clients = is_array($clients) ? $clients : [];
        $dcr = Server::dcrEnabled();
        $mcp = rest_url('nucleo/v1/mcp');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- parametri di esito dopo admin-post (già verificato con nonce), usati solo per mostrare un avviso.
        $added = self::requestString($_GET, 'added');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo per mostrare un avviso.
        $addError = self::requestString($_GET, 'add_error');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo per mostrare un avviso.
        $deleted = self::requestString($_GET, 'deleted');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo per mostrare un avviso.
        $htaccess = self::requestString($_GET, 'htaccess');
        $postUrl = admin_url('admin-post.php');
        $pendingSecret = self::takePendingSecret();
        $headerMissing = get_transient(RestController::HEADER_MISSING_TRANSIENT) !== false;
        $domains = implode("\n", Server::allowedDomains());

        $root = dirname(__DIR__, 2);
        wp_enqueue_script(
            'nucleo-admin-copy',
            plugin_dir_url($root . '/nucleo.php') . 'assets/admin/copy.js',
            ['wp-i18n'],
            Editor::assetVersion('assets/admin/copy.js'),
            true
        );
        wp_set_script_translations('nucleo-admin-copy', 'nucleo', $root . '/languages');
        wp_add_inline_script('nucleo-admin-copy', "(function(){var tabs=[].slice.call(document.querySelectorAll('[data-nc-qs]'));var panels=document.querySelectorAll('[data-nc-qs-panel]');function show(tab,focus){var key=tab.getAttribute('data-nc-qs');tabs.forEach(function(t){var on=t===tab;t.setAttribute('aria-selected',on?'true':'false');t.tabIndex=on?0:-1;});panels.forEach(function(p){p.hidden=p.getAttribute('data-nc-qs-panel')!==key;});if(focus){tab.focus();}}tabs.forEach(function(tab,i){tab.addEventListener('click',function(){show(tab,false);});tab.addEventListener('keydown',function(e){var d=e.key==='ArrowRight'?1:e.key==='ArrowLeft'?-1:0;if(d){e.preventDefault();show(tabs[(i+d+tabs.length)%tabs.length],true);}});});})();");
        ?>
        <div class="wrap nucleo-admin">
            <?php echo wp_kses(Icons::sprite(), \Nucleo\Support\AllowedHtml::svg()); ?>
            <div class="nc-page">
                <header class="nc-page__head">
                    <div class="nc-page__intro">
                        <?php echo wp_kses(Icons::tile('nc-agenti'), \Nucleo\Support\AllowedHtml::svg()); ?>
                        <div>
                            <span class="nc-page__kicker">Nucleo</span>
                            <h1 class="nc-page__title"><?php esc_html_e('AI Agents', 'nucleo'); ?></h1>
                            <p class="nc-page__subtitle"><?php esc_html_e('Connect agents such as Claude Desktop or Claude Code to this site.', 'nucleo'); ?></p>
                        </div>
                    </div>
                </header>

                <div class="nc-page__notices">
                    <?php if ($added !== '') : ?>
                        <div class="nc-notice nc-notice--ok" role="status">
                            <span class="nc-notice__glyph" aria-hidden="true">✓</span>
                            <div class="nc-notice__text">
                                <?php esc_html_e('Agent added. Copy the client_id below: it will not be shown again.', 'nucleo'); ?>
                                <code class="nc-code"><?php echo esc_html($added); ?></code>
                            </div>
                            <div class="nc-notice__actions">
                                <button type="button" class="nc-btn nc-btn--secondary nc-btn--small" data-nc-copy="<?php echo esc_attr($added); ?>"><?php esc_html_e('Copy', 'nucleo'); ?></button>
                                <span aria-live="polite" class="nc-muted"></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($pendingSecret !== null) : ?>
                        <div class="nc-notice nc-notice--ok" role="status">
                            <span class="nc-notice__glyph" aria-hidden="true">✓</span>
                            <div class="nc-notice__text">
                                <?php esc_html_e('Confidential agent added. Copy the client_id and the client secret now: the secret will not be shown again.', 'nucleo'); ?>
                                <div><?php esc_html_e('Client ID:', 'nucleo'); ?> <code class="nc-code"><?php echo esc_html($pendingSecret['client_id']); ?></code></div>
                                <div><?php esc_html_e('Client secret:', 'nucleo'); ?> <code class="nc-code"><?php echo esc_html($pendingSecret['secret']); ?></code></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($addError !== '') : ?>
                        <div class="nc-notice nc-notice--err" role="alert">
                            <span class="nc-notice__glyph" aria-hidden="true">✕</span>
                            <div class="nc-notice__text"><?php echo esc_html(self::addErrorMessage($addError)); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($headerMissing) : ?>
                        <div class="nc-notice nc-notice--warn" role="alert">
                            <span class="nc-notice__glyph" aria-hidden="true">▲</span>
                            <div class="nc-notice__text">
                                <?php esc_html_e('Requests from AI agents reached this site without the Authorization header, so they cannot sign in. The server may be removing it: add a rule (Apache, .htaccess) that passes it to WordPress.', 'nucleo'); ?>
                            </div>
                            <div class="nc-notice__actions">
                                <form method="post" action="<?php echo esc_url($postUrl); ?>">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(self::HTACCESS_ACTION); ?>">
                                    <?php wp_nonce_field(self::HTACCESS_ACTION); ?>
                                    <button type="submit" class="nc-btn nc-btn--secondary nc-btn--small"><?php esc_html_e('Write the .htaccess rule', 'nucleo'); ?></button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($htaccess === 'ok') : ?>
                        <div class="nc-notice nc-notice--ok" role="status">
                            <span class="nc-notice__glyph" aria-hidden="true">✓</span>
                            <div class="nc-notice__text"><?php esc_html_e('Rule written to .htaccess.', 'nucleo'); ?></div>
                        </div>
                    <?php elseif ($htaccess === 'fail') : ?>
                        <div class="nc-notice nc-notice--err" role="alert">
                            <span class="nc-notice__glyph" aria-hidden="true">✕</span>
                            <div class="nc-notice__text"><?php esc_html_e('The .htaccess file could not be written: check its permissions, or add the rule by hand.', 'nucleo'); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo per mostrare un avviso.
                    if (isset($_GET['revoked'])) : ?>
                        <div class="nc-notice nc-notice--ok" role="status">
                            <span class="nc-notice__glyph" aria-hidden="true">✓</span>
                            <div class="nc-notice__text"><?php esc_html_e('Tokens revoked.', 'nucleo'); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($deleted === '1') : ?>
                        <div class="nc-notice nc-notice--ok" role="status">
                            <span class="nc-notice__glyph" aria-hidden="true">✓</span>
                            <div class="nc-notice__text"><?php esc_html_e('Agent deleted.', 'nucleo'); ?></div>
                        </div>
                    <?php elseif ($deleted === '0') : ?>
                        <div class="nc-notice nc-notice--warn" role="alert">
                            <span class="nc-notice__glyph" aria-hidden="true">▲</span>
                            <div class="nc-notice__text"><?php esc_html_e('Agent not found: it may have already been deleted.', 'nucleo'); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo per mostrare un avviso.
                    if (isset($_GET['saved'])) : ?>
                        <div class="nc-notice nc-notice--ok" role="status">
                            <span class="nc-notice__glyph" aria-hidden="true">✓</span>
                            <div class="nc-notice__text"><?php esc_html_e('Settings saved.', 'nucleo'); ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="nc-grid">
                    <section class="nc-card nc-col-8" aria-labelledby="nucleo-agents-connect">
                        <div class="nc-card__head">
                            <h2 id="nucleo-agents-connect" class="nc-card__title"><?php esc_html_e('How to connect an agent', 'nucleo'); ?></h2>
                        </div>
                        <div class="nc-card__body">
                            <div class="nc-field">
                                <label class="nc-field__label" for="nucleo-mcp-url"><?php esc_html_e('MCP server URL', 'nucleo'); ?></label>
                                <div class="nc-row nc-row--nowrap">
                                    <input type="text" id="nucleo-mcp-url" class="nc-input" readonly value="<?php echo esc_attr($mcp); ?>">
                                    <button type="button" class="nc-btn nc-btn--secondary" data-nc-copy data-nc-copy-target="#nucleo-mcp-url"><?php esc_html_e('Copy', 'nucleo'); ?></button>
                                    <span aria-live="polite" class="nc-muted"></span>
                                </div>
                            </div>
                            <ol>
                                <li><?php esc_html_e("In the MCP client (for example Claude Desktop), enter the MCP server URL.", 'nucleo'); ?></li>
                                <li><?php esc_html_e("The client opens the WordPress authorization page: sign in and approve. From there it gets its tokens on its own.", 'nucleo'); ?></li>
                                <li><?php esc_html_e("If the client asks for an already registered client_id, use the one of an agent added on this page.", 'nucleo'); ?></li>
                            </ol>
                            <?php if ($dcr) : ?>
                                <p class="nc-muted"><?php esc_html_e('Dynamic registration is on: clients can register on their own.', 'nucleo'); ?></p>
                            <?php else : ?>
                                <p class="nc-muted"><?php esc_html_e('Dynamic registration is off: to connect an agent, add it below with "Add agent".', 'nucleo'); ?></p>
                            <?php endif; ?>
                            <p class="nc-muted"><?php esc_html_e('Step-by-step instructions for each agent are in "Connect your agent" below.', 'nucleo'); ?></p>
                        </div>
                    </section>

                    <section class="nc-card nc-col-4" aria-labelledby="nucleo-agents-dcr">
                        <div class="nc-card__head">
                            <h2 id="nucleo-agents-dcr" class="nc-card__title"><?php esc_html_e('Dynamic registration', 'nucleo'); ?></h2>
                            <form method="post" action="<?php echo esc_url($postUrl); ?>">
                                <input type="hidden" name="action" value="<?php echo esc_attr(self::DCR_ACTION); ?>">
                                <?php wp_nonce_field(self::DCR_ACTION); ?>
                                <?php if ($dcr) : ?>
                                    <button type="submit" class="nc-toggle" role="switch" aria-checked="true" aria-label="<?php esc_attr_e('Allow clients to register automatically (RFC 7591)', 'nucleo'); ?>"></button>
                                <?php else : ?>
                                    <button type="submit" name="dcr_enabled" value="1" class="nc-toggle" role="switch" aria-checked="false" aria-label="<?php esc_attr_e('Allow clients to register automatically (RFC 7591)', 'nucleo'); ?>"></button>
                                <?php endif; ?>
                            </form>
                        </div>
                        <div class="nc-card__body">
                            <p class="nc-muted"><?php esc_html_e('When on, clients can register on their own. Leave it off if you do not need it.', 'nucleo'); ?></p>
                        </div>
                    </section>

                    <?php self::renderGuides($mcp, $dcr); ?>
                </div>

                <div class="nc-stack">
                    <section class="nc-card" aria-labelledby="nucleo-agents-list">
                        <div class="nc-card__head">
                            <h2 id="nucleo-agents-list" class="nc-card__title"><?php esc_html_e('Connected agents', 'nucleo'); ?></h2>
                            <span class="nc-muted"><?php /* translators: %d: number of connected agents. */ echo esc_html(sprintf(_n('%d agent', '%d agents', count($clients), 'nucleo'), count($clients))); ?></span>
                        </div>
                        <div class="nc-card__body">
                            <p class="nc-muted"><?php esc_html_e("Delete an agent to remove it together with all its tokens. Revoke its tokens to disconnect it without deleting it.", 'nucleo'); ?></p>
                        </div>
                        <div class="nc-table-wrap">
                            <table class="nc-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Name', 'nucleo'); ?></th>
                                        <th><?php esc_html_e('Type', 'nucleo'); ?></th>
                                        <th><?php esc_html_e('Authorized by', 'nucleo'); ?></th>
                                        <th><?php esc_html_e('Registered on', 'nucleo'); ?></th>
                                        <th><?php esc_html_e('Last used', 'nucleo'); ?></th>
                                        <th><?php esc_html_e('Active tokens', 'nucleo'); ?></th>
                                        <th><?php esc_html_e('Scopes', 'nucleo'); ?></th>
                                        <th><?php esc_html_e('Actions', 'nucleo'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($clients === []) : ?>
                                        <tr><td colspan="8" class="nc-muted"><?php esc_html_e('No connected agents.', 'nucleo'); ?></td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($clients as $client) : ?>
                                        <?php
                                        $id = (string) $client['client_id'];
                                        $name = (string) $client['name'];
                                        $kind = (string) $client['kind'];
                                        $scopeText = $scopes[$id] ?? '';
                                        $lastUsed = (int) $client['last_used'];
                                        $author = isset($authors[$id]) && $authors[$id] > 0 ? get_userdata($authors[$id]) : false;
                                        ?>
                                        <tr>
                                            <td><?php echo esc_html($name !== '' ? $name : $id); ?></td>
                                            <td>
                                                <span class="nc-pill nc-pill--neutral"><?php echo esc_html(self::typeLabel($kind)); ?></span>
                                                <?php if ((int) $client['confidential'] === 1) : ?>
                                                    <span class="nc-pill nc-pill--neutral"><?php esc_html_e('Confidential', 'nucleo'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo esc_html($author !== false ? $author->display_name : '—'); ?></td>
                                            <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $client['created'])); ?></td>
                                            <td><?php echo esc_html($lastUsed > 0 ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $lastUsed) : __('Never', 'nucleo')); ?></td>
                                            <td><?php echo esc_html((string) ($counts[$id] ?? 0)); ?></td>
                                            <td class="nc-muted"><?php echo esc_html($scopeText !== '' ? $scopeText : '—'); ?></td>
                                            <td>
                                                <div class="nc-table__actions">
                                                    <form method="post" action="<?php echo esc_url($postUrl); ?>">
                                                        <input type="hidden" name="action" value="<?php echo esc_attr(self::REVOKE_ACTION); ?>">
                                                        <input type="hidden" name="client_id" value="<?php echo esc_attr($id); ?>">
                                                        <?php wp_nonce_field(self::REVOKE_ACTION); ?>
                                                        <button type="submit" class="nc-btn nc-btn--secondary nc-btn--small"><?php esc_html_e('Revoke tokens', 'nucleo'); ?></button>
                                                    </form>
                                                    <form method="post" action="<?php echo esc_url($postUrl); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete this agent and all its tokens? This cannot be undone.', 'nucleo')); ?>');">
                                                        <input type="hidden" name="action" value="<?php echo esc_attr(self::DELETE_ACTION); ?>">
                                                        <input type="hidden" name="client_id" value="<?php echo esc_attr($id); ?>">
                                                        <?php wp_nonce_field(self::DELETE_ACTION); ?>
                                                        <button type="submit" class="nc-btn nc-btn--danger nc-btn--small"><?php esc_html_e('Delete', 'nucleo'); ?></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="nc-card" aria-labelledby="nucleo-agents-add">
                        <div class="nc-card__head">
                            <h2 id="nucleo-agents-add" class="nc-card__title"><?php esc_html_e('Add agent', 'nucleo'); ?></h2>
                        </div>
                        <div class="nc-card__body">
                            <form method="post" action="<?php echo esc_url($postUrl); ?>" class="nc-stack">
                                <input type="hidden" name="action" value="<?php echo esc_attr(self::ADD_ACTION); ?>">
                                <?php wp_nonce_field(self::ADD_ACTION); ?>
                                <div class="nc-grid">
                                    <div class="nc-field nc-col-6">
                                        <label class="nc-field__label" for="nucleo-agent-name"><?php esc_html_e('Name', 'nucleo'); ?></label>
                                        <input type="text" id="nucleo-agent-name" name="name" class="nc-input" maxlength="200" required>
                                    </div>
                                    <div class="nc-field nc-col-6">
                                        <label class="nc-field__label" for="nucleo-agent-redirect"><?php esc_html_e('Redirect URL', 'nucleo'); ?></label>
                                        <input type="url" id="nucleo-agent-redirect" name="redirect_uri" class="nc-input" placeholder="http://127.0.0.1:33418/callback" required>
                                        <p class="nc-field__help"><?php esc_html_e('https, or http only on localhost or 127.0.0.1.', 'nucleo'); ?></p>
                                    </div>
                                </div>
                                <div>
                                    <button type="submit" class="nc-btn nc-btn--primary"><?php esc_html_e('Add agent', 'nucleo'); ?></button>
                                </div>
                            </form>
                        </div>
                    </section>

                    <section class="nc-card" aria-labelledby="nucleo-agents-confidential">
                        <div class="nc-card__head">
                            <h2 id="nucleo-agents-confidential" class="nc-card__title"><?php esc_html_e('Add confidential agent', 'nucleo'); ?></h2>
                        </div>
                        <div class="nc-card__body">
                            <p class="nc-muted"><?php esc_html_e('A confidential agent authenticates with a client secret, generated here and shown once right after it is created.', 'nucleo'); ?></p>
                            <form method="post" action="<?php echo esc_url($postUrl); ?>" class="nc-stack">
                                <input type="hidden" name="action" value="<?php echo esc_attr(self::CONFIDENTIAL_ACTION); ?>">
                                <?php wp_nonce_field(self::CONFIDENTIAL_ACTION); ?>
                                <div class="nc-grid">
                                    <div class="nc-field nc-col-6">
                                        <label class="nc-field__label" for="nucleo-confidential-name"><?php esc_html_e('Name', 'nucleo'); ?></label>
                                        <input type="text" id="nucleo-confidential-name" name="name" class="nc-input" maxlength="200" required>
                                    </div>
                                    <div class="nc-field nc-col-6">
                                        <label class="nc-field__label" for="nucleo-confidential-redirects"><?php esc_html_e('Redirect URLs', 'nucleo'); ?></label>
                                        <textarea id="nucleo-confidential-redirects" name="redirect_uris" class="nc-input" rows="3" placeholder="http://127.0.0.1:33418/callback" required></textarea>
                                        <p class="nc-field__help"><?php esc_html_e('One per line. https, or http only on localhost or 127.0.0.1.', 'nucleo'); ?></p>
                                    </div>
                                </div>
                                <div>
                                    <button type="submit" class="nc-btn nc-btn--primary"><?php esc_html_e('Create confidential agent', 'nucleo'); ?></button>
                                </div>
                            </form>
                        </div>
                    </section>

                    <section class="nc-card" aria-labelledby="nucleo-agents-domains">
                        <div class="nc-card__head">
                            <h2 id="nucleo-agents-domains" class="nc-card__title"><?php esc_html_e('Allowed domains', 'nucleo'); ?></h2>
                        </div>
                        <div class="nc-card__body">
                            <p class="nc-muted"><?php esc_html_e('When this list is not empty, clients that use a metadata document or dynamic registration are accepted only if their host, or their redirect host, is one of these domains or a subdomain of one. One domain per line. Leave it empty for no limit.', 'nucleo'); ?></p>
                            <form method="post" action="<?php echo esc_url($postUrl); ?>" class="nc-stack">
                                <input type="hidden" name="action" value="<?php echo esc_attr(self::DOMAINS_ACTION); ?>">
                                <?php wp_nonce_field(self::DOMAINS_ACTION); ?>
                                <div class="nc-field">
                                    <label class="nc-field__label" for="nucleo-oauth-domains"><?php esc_html_e('Domains', 'nucleo'); ?></label>
                                    <textarea id="nucleo-oauth-domains" name="domains" class="nc-input" rows="4" placeholder="example.com"><?php echo esc_textarea($domains); ?></textarea>
                                </div>
                                <div>
                                    <button type="submit" class="nc-btn nc-btn--primary"><?php esc_html_e('Save domains', 'nucleo'); ?></button>
                                </div>
                            </form>
                        </div>
                    </section>
                </div>
            </div>
        </div>
        <?php
    }

    /** Aggiunta da form: crea il client e torna alla pagina con ?added=<client_id> oppure ?add_error=<codice>. */
    public static function addFromPost(): void
    {
        self::assertAdmin();
        check_admin_referer(self::ADD_ACTION);

        $result = self::addClient(
            self::requestString($_POST, 'name'),
            esc_url_raw(self::requestString($_POST, 'redirect_uri'))
        );
        $args = is_string($result) ? ['added' => $result] : ['add_error' => $result->get_error_code()];

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . self::SLUG)));
        exit;
    }

    /**
     * Aggiunta di un client confidenziale. Il secret va all'utente che l'ha creato tramite transient di breve durata,
     * non nell'URL: viene mostrato una volta sola al ritorno sulla pagina.
     */
    public static function addConfidentialFromPost(): void
    {
        self::assertAdmin();
        check_admin_referer(self::CONFIDENTIAL_ACTION);

        $result = self::addConfidentialClient(
            self::requestString($_POST, 'name'),
            self::textareaString($_POST, 'redirect_uris')
        );
        if ($result instanceof \WP_Error) {
            $args = ['add_error' => $result->get_error_code()];
        } else {
            set_transient(self::SECRET_TRANSIENT . get_current_user_id(), $result, self::SECRET_TTL);
            $args = ['confidential' => '1'];
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . self::SLUG)));
        exit;
    }

    /** Elimina il client e tutti i suoi token. Torna alla pagina con ?deleted=1 oppure ?deleted=0. */
    public static function deleteClient(): void
    {
        self::assertAdmin();
        check_admin_referer(self::DELETE_ACTION);

        $clientId = self::requestString($_POST, 'client_id');
        $deleted = $clientId !== '' && (new Server())->deleteClient($clientId);

        wp_safe_redirect(add_query_arg('deleted', $deleted ? '1' : '0', admin_url('admin.php?page=' . self::SLUG)));
        exit;
    }

    public static function revokeClient(): void
    {
        self::assertAdmin();
        check_admin_referer(self::REVOKE_ACTION);

        $clientId = self::requestString($_POST, 'client_id');
        if ($clientId !== '') {
            self::revokeTokens($clientId);
        }

        wp_safe_redirect(add_query_arg('revoked', '1', admin_url('admin.php?page=' . self::SLUG)));
        exit;
    }

    public static function toggleDcr(): void
    {
        self::assertAdmin();
        check_admin_referer(self::DCR_ACTION);

        update_option(self::DCR_OPTION, isset($_POST['dcr_enabled']), false);

        wp_safe_redirect(add_query_arg('saved', '1', admin_url('admin.php?page=' . self::SLUG)));
        exit;
    }

    /** Salva la lista dei domini ammessi (uno per riga, solo nomi host validi). */
    public static function saveDomainsFromPost(): void
    {
        self::assertAdmin();
        check_admin_referer(self::DOMAINS_ACTION);

        update_option(self::DOMAINS_OPTION, Server::parseDomainList(self::textareaString($_POST, 'domains')), false);

        wp_safe_redirect(add_query_arg('saved', '1', admin_url('admin.php?page=' . self::SLUG)));
        exit;
    }

    /**
     * Scrive nel .htaccess della radice WordPress la regola che passa l'header Authorization a PHP.
     * Torna alla pagina con ?htaccess=ok oppure ?htaccess=fail.
     */
    public static function writeHtaccessFromPost(): void
    {
        self::assertAdmin();
        check_admin_referer(self::HTACCESS_ACTION);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $written = insert_with_markers(get_home_path() . '.htaccess', 'Nucleo', [
            'RewriteEngine On',
            'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
        ]);
        if ($written) {
            delete_transient(RestController::HEADER_MISSING_TRANSIENT);
        }

        wp_safe_redirect(add_query_arg('htaccess', $written ? 'ok' : 'fail', admin_url('admin.php?page=' . self::SLUG)));
        exit;
    }

    /** Messaggio per il codice di errore dell'aggiunta; un codice sconosciuto ottiene un messaggio generico. */
    /** Schede "Collega il tuo agente": una per client, con passi, configurazioni da copiare e URL di ritorno. */
    private static function renderGuides(string $mcp, bool $dcr): void
    {
        // Cartella di installazione di WordPress per l'opzione --path di WP-CLI nella configurazione locale.
        $guides = AgentGuides::all($mcp, untrailingslashit(ABSPATH));
        $public = self::isPublicUrl($mcp);
        ?>
        <section class="nc-card nc-col-12" aria-labelledby="nucleo-agents-guides">
            <div class="nc-card__head">
                <h2 id="nucleo-agents-guides" class="nc-card__title"><?php esc_html_e('Connect your agent', 'nucleo'); ?></h2>
            </div>
            <div class="nc-card__body nc-stack">
                <div class="nc-tabs" role="tablist" aria-label="<?php esc_attr_e('Agents', 'nucleo'); ?>">
                    <?php foreach ($guides as $i => $guide) : ?>
                        <button type="button" class="nc-tab" role="tab" id="nucleo-guide-tab-<?php echo esc_attr($guide['key']); ?>" aria-controls="nucleo-guide-<?php echo esc_attr($guide['key']); ?>" aria-selected="<?php echo esc_attr($i === 0 ? 'true' : 'false'); ?>" tabindex="<?php echo esc_attr($i === 0 ? '0' : '-1'); ?>" data-nc-qs="<?php echo esc_attr($guide['key']); ?>"><?php echo esc_html($guide['label']); ?></button>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($guides as $i => $guide) : ?>
                    <div class="nc-stack" role="tabpanel" id="nucleo-guide-<?php echo esc_attr($guide['key']); ?>" aria-labelledby="nucleo-guide-tab-<?php echo esc_attr($guide['key']); ?>" data-nc-qs-panel="<?php echo esc_attr($guide['key']); ?>"<?php echo esc_attr($i === 0 ? '' : ' hidden'); ?>>
                        <?php if ($guide['cloud'] && !$public) : ?>
                            <div class="nc-notice nc-notice--warn" role="note">
                                <span class="nc-notice__glyph" aria-hidden="true">▲</span>
                                <div class="nc-notice__text"><?php esc_html_e('This agent connects from its own servers: the site must be reachable from the internet over https. This address is not, so the connection fails until the site is online.', 'nucleo'); ?></div>
                            </div>
                        <?php endif; ?>
                        <ol>
                            <?php foreach ($guide['steps'] as $step) : ?>
                                <li><?php echo esc_html($step); ?></li>
                            <?php endforeach; ?>
                        </ol>
                        <?php foreach ($guide['snippets'] as $j => $snippet) : ?>
                            <?php $id = 'nucleo-guide-' . $guide['key'] . '-' . $j; ?>
                            <div>
                                <p class="nc-label"><?php echo esc_html($snippet['label']); ?></p>
                                <div class="nc-snippet">
                                    <pre><code id="<?php echo esc_attr($id); ?>"><?php echo esc_html($snippet['code']); ?></code></pre>
                                    <div class="nc-snippet__actions">
                                        <button type="button" class="nc-btn nc-btn--secondary nc-btn--small" data-nc-copy data-nc-copy-target="#<?php echo esc_attr($id); ?>"><?php esc_html_e('Copy', 'nucleo'); ?></button>
                                        <span aria-live="polite" class="nc-muted"></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($guide['callbacks'] !== [] && !$dcr) : ?>
                            <p class="nc-muted">
                                <?php esc_html_e('Dynamic registration is off. If the agent does not connect, add it with "Add agent" and this redirect URL:', 'nucleo'); ?>
                                <?php foreach ($guide['callbacks'] as $callback) : ?>
                                    <code class="nc-code"><?php echo esc_html($callback); ?></code>
                                <?php endforeach; ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($guide['notes'] !== []) : ?>
                            <ul class="nc-muted">
                                <?php foreach ($guide['notes'] as $note) : ?>
                                    <li><?php echo esc_html($note); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    /** Vero se l'URL è raggiungibile da internet: https e host non locale (i client cloud si collegano dai loro server). */
    public static function isPublicUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '' || $host === 'localhost' || preg_match('/\.(localhost|local|test|internal|lan)$/', $host) === 1) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }

    private static function addErrorMessage(string $code): string
    {
        return match ($code) {
            'missing_name' => __('Enter a name for the agent.', 'nucleo'),
            'invalid_redirect' => __('The return address must use https, or http only on localhost or 127.0.0.1.', 'nucleo'),
            default => __('Agent not added: check the name and the redirect URL (https, or http only on localhost or 127.0.0.1).', 'nucleo'),
        };
    }

    /** Etichetta del tipo di client nella tabella. */
    private static function typeLabel(string $kind): string
    {
        return match ($kind) {
            'pre' => __('Pre-registered', 'nucleo'),
            'cimd' => __('Metadata document', 'nucleo'),
            'dcr' => __('Dynamic registration', 'nucleo'),
            default => $kind,
        };
    }

    /**
     * Nome validato e ripulito, oppure WP_Error con codice 'missing_name' o 'name_too_long'.
     *
     * @return string|\WP_Error
     */
    private static function checkName(string $name): string|\WP_Error
    {
        $name = trim($name);
        if ($name === '') {
            return new \WP_Error('missing_name', __("The agent name is required.", 'nucleo'));
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return new \WP_Error('name_too_long', __('The agent name is too long.', 'nucleo'));
        }

        return $name;
    }

    /**
     * Righe non vuote di un campo testo multilinea.
     *
     * @return list<string>
     */
    private static function lines(string $raw): array
    {
        $parts = preg_split('/\R/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $line): bool => $line !== ''));
    }

    /**
     * Legge e consuma il secret mostrato dopo la creazione dell'utente corrente. Restituisce null se non c'è.
     *
     * @return array{client_id:string,secret:string}|null
     */
    private static function takePendingSecret(): ?array
    {
        $key = self::SECRET_TRANSIENT . get_current_user_id();
        $value = get_transient($key);
        if (!is_array($value) || !is_string($value['client_id'] ?? null) || !is_string($value['secret'] ?? null)) {
            return null;
        }
        delete_transient($key);

        return ['client_id' => $value['client_id'], 'secret' => $value['secret']];
    }

    /** Controlla la capability delle azioni POST. Il nonce lo verifica il chiamante con check_admin_referer(). */
    private static function assertAdmin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'nucleo'), '', ['response' => 403]);
        }
    }

    /**
     * Legge una stringa da $_GET o $_POST, ripulita come testo semplice. Il nonce lo verifica il chiamante.
     *
     * @param array<mixed> $source
     */
    private static function requestString(array $source, string $key): string
    {
        $value = $source[$key] ?? '';

        return is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
    }

    /**
     * Come requestString, ma conserva le righe: per i campi con più valori (uno per riga).
     *
     * @param array<mixed> $source
     */
    private static function textareaString(array $source, string $key): string
    {
        $value = $source[$key] ?? '';

        return is_string($value) ? sanitize_textarea_field(wp_unslash($value)) : '';
    }

    /** Installa le tabelle OAuth se mancano (come fa Server per le proprie query). */
    private static function ensureSchema(): void
    {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            Server::install();
        }
    }

    private static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . 'nucleo_oauth_' . $name;
    }
}
