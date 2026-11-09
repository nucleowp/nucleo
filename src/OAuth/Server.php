<?php

declare(strict_types=1);

namespace Nucleo\OAuth;

/**
 * Authorization server OAuth 2.1 di Nucleo: client, codici di autorizzazione, token e revoca.
 * Nel database finisce solo l'hash dei token. Il codice è monouso (revoked = 1 significa "usato"),
 * il refresh token ruota a ogni uso e il riuso di uno già ruotato revoca tutta la catena.
 * revoked = 2 significa "revocato da un admin o via RFC 7009": il refresh non ripropone la catena.
 * Le regole pure (resource, domini, https, throttle di last_used, credenziali del client) sono statiche e testabili.
 */
final class Server
{
    private const DB_OPTION = 'nucleo_oauth_db';
    private const DB_VERSION = '2';
    private const DCR_OPTION = 'nucleo_oauth_dcr';
    private const DOMAINS_OPTION = 'nucleo_oauth_domains';
    private const CODE_TTL = 60;
    private const ACCESS_TTL = 3600;
    private const REFRESH_TTL = 2592000;
    private const TOUCH_INTERVAL = 300;
    private const CLIENT_KINDS = ['pre', 'cimd', 'dcr'];
    private const REVOKED_CONSUMED = 1;
    private const REVOKED_ADMIN = 2;
    private const DEV_ENVIRONMENTS = ['local', 'development'];

    public const AUTH_NONE = 'none';
    public const AUTH_POST = 'client_secret_post';
    public const AUTH_BASIC = 'client_secret_basic';

    private UrlGuard $guard;

    public function __construct(?UrlGuard $guard = null)
    {
        $this->guard = $guard ?? new UrlGuard();
    }

    /** Crea o aggiorna le tabelle dei client e dei token (dbDelta). */
    public static function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $clients = self::table('clients');
        $tokens = self::table('tokens');

        dbDelta("CREATE TABLE $clients (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_id varchar(64) NOT NULL,
            name varchar(200) NOT NULL DEFAULT '',
            redirect_uris text NOT NULL,
            kind varchar(8) NOT NULL,
            secret_hash varchar(255) DEFAULT NULL,
            created bigint(20) unsigned NOT NULL DEFAULT 0,
            last_used bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY client_id (client_id)
        ) $charset;");

        dbDelta("CREATE TABLE $tokens (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_hash char(64) NOT NULL,
            kind varchar(8) NOT NULL,
            client_id varchar(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            scopes varchar(255) NOT NULL DEFAULT '',
            resource varchar(255) NOT NULL DEFAULT '',
            code_challenge varchar(128) NOT NULL DEFAULT '',
            redirect_uri varchar(2048) NOT NULL DEFAULT '',
            chain_id varchar(64) NOT NULL DEFAULT '',
            expires bigint(20) unsigned NOT NULL DEFAULT 0,
            revoked tinyint(1) unsigned NOT NULL DEFAULT 0,
            created bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY chain_id (chain_id)
        ) $charset;");

        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    /** La registrazione dinamica (RFC 7591) è disattivata di default. */
    public static function dcrEnabled(): bool
    {
        return filter_var(get_option(self::DCR_OPTION, false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Lista dei domini ammessi per i client CIMD e la registrazione dinamica. Lista vuota = nessun vincolo.
     *
     * @return list<string>
     */
    public static function allowedDomains(): array
    {
        $raw = get_option(self::DOMAINS_OPTION, []);

        return is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
    }

    /**
     * Vero se l'host è uguale a un dominio della lista o ne è un sottodominio. Lista vuota: sempre vero.
     *
     * @param list<string> $domains
     */
    public static function hostAllowed(string $host, array $domains): bool
    {
        if ($domains === []) {
            return true;
        }
        $host = strtolower(rtrim($host, '.'));
        if ($host === '') {
            return false;
        }
        foreach ($domains as $domain) {
            $domain = strtolower($domain);
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizza il testo del campo domini (uno per riga o separato da virgole): minuscole, senza punto finale,
     * solo nomi host validi, senza duplicati. Le voci non valide vengono scartate.
     *
     * @return list<string>
     */
    public static function parseDomainList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', strtolower($raw)) ?: [] as $item) {
            $host = trim($item, '.');
            if ($host !== '' && preg_match('/\A[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*\z/', $host) === 1) {
                $out[] = $host;
            }
        }

        return array_values(array_unique($out));
    }

    /** Il token vale solo per la risorsa per cui è stato emesso; senza resource (token precedenti) resta valido. */
    public static function resourceMatches(string $stored, string $canonical): bool
    {
        return $stored === '' || $stored === $canonical;
    }

    /** Vero se last_used va aggiornato: al più una scrittura ogni TOUCH_INTERVAL secondi. */
    public static function lastUsedDue(int $lastUsed, int $now): bool
    {
        return $now - $lastUsed >= self::TOUCH_INTERVAL;
    }

    /**
     * Vero se il server ha tolto l'header Authorization ma il client ha inviato un Bearer: solo allora
     * conviene segnalarlo. Riceve $_SERVER e le intestazioni grezze (apache_request_headers), quindi è testabile senza WordPress.
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $headers
     */
    public static function bearerHeaderDropped(array $server, array $headers): bool
    {
        if (!empty($server['HTTP_AUTHORIZATION']) || !empty($server['REDIRECT_HTTP_AUTHORIZATION'])) {
            return false;
        }
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'authorization' && is_string($value)) {
                return str_starts_with(strtolower(ltrim($value)), 'bearer ');
            }
        }

        return false;
    }

    /**
     * Vero se il client registrato è ammesso dalla lista dei domini: i CIMD (client_id https) devono stare su un
     * host della lista, i DCR su un host di ogni redirect. Gli altri tipi non sono soggetti alla lista.
     *
     * @param list<string> $redirectUris
     * @param list<string> $domains
     */
    public static function clientAllowed(string $kind, string $clientId, array $redirectUris, array $domains): bool
    {
        if ($domains === []) {
            return true;
        }
        if ($kind === 'cimd') {
            return self::hostAllowed((string) wp_parse_url($clientId, PHP_URL_HOST), $domains);
        }
        if ($kind === 'dcr') {
            foreach ($redirectUris as $uri) {
                if (!self::hostAllowed((string) wp_parse_url($uri, PHP_URL_HOST), $domains)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Vero se la richiesta va rifiutata con https_required: non è TLS e l'ambiente non è di sviluppo. */
    public static function rejectsPlainHttp(bool $isSsl, string $environmentType): bool
    {
        return !$isSsl && !in_array($environmentType, self::DEV_ENVIRONMENTS, true);
    }

    /**
     * Legge le credenziali del client. Restituisce null se l'header Basic è malformato
     * o se il client si presenta con più di un metodo (secret nel body e nell'header, o client_id discordanti).
     *
     * @return array{client_id:string,secret:string,method:string}|null
     */
    public static function parseClientAuth(string $bodyClientId, string $bodySecret, string $authorization): ?array
    {
        $basic = null;
        if ($authorization !== '') {
            if (preg_match('/\ABasic\s+([A-Za-z0-9+\/=]+)\z/i', trim($authorization), $m) !== 1) {
                return null;
            }
            $decoded = base64_decode($m[1], true);
            if ($decoded === false || !str_contains($decoded, ':')) {
                return null;
            }
            [$id, $secret] = explode(':', $decoded, 2);
            $basic = ['client_id' => urldecode($id), 'secret' => urldecode($secret)];
        }

        if ($basic !== null) {
            if ($bodySecret !== '' || ($bodyClientId !== '' && $bodyClientId !== $basic['client_id'])) {
                return null;
            }

            return ['client_id' => $basic['client_id'], 'secret' => $basic['secret'], 'method' => self::AUTH_BASIC];
        }

        if ($bodySecret !== '') {
            return ['client_id' => $bodyClientId, 'secret' => $bodySecret, 'method' => self::AUTH_POST];
        }

        return ['client_id' => $bodyClientId, 'secret' => '', 'method' => self::AUTH_NONE];
    }

    /**
     * @param array<string,mixed> $meta redirect_uris (lista), client_name (facoltativo), secret_hash (facoltativo, client confidenziale)
     * @return array{client_id:string,client_name:string,redirect_uris:list<string>}
     * @throws \InvalidArgumentException se i metadati non sono validi
     * @throws \RuntimeException se il salvataggio nel database fallisce
     */
    public function registerClient(array $meta, string $kind = 'dcr'): array
    {
        if (!in_array($kind, self::CLIENT_KINDS, true)) {
            /* translators: %s: client kind, one of pre, cimd or dcr. */
            $message = sprintf(__('Invalid client type: %s', 'nucleo'), $kind);
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException($message);
        }

        $uris = $meta['redirect_uris'] ?? null;
        if (!is_array($uris) || !array_is_list($uris) || $uris === []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException(__('redirect_uris must be a list with at least one URI.', 'nucleo'));
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || !$this->isAcceptableRedirect($uri)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new \InvalidArgumentException(__('redirect_uris only accepts https, or http on localhost / 127.0.0.1.', 'nucleo'));
            }
            if ($kind === 'dcr' && !self::hostAllowed(self::hostOf($uri), self::allowedDomains())) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new \InvalidArgumentException(__('The redirect host is not in the allowed domains list.', 'nucleo'));
            }
        }

        $name = $meta['client_name'] ?? '';
        if (!is_string($name) || mb_strlen($name) > 200) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException(__('client_name must be a string of at most 200 characters.', 'nucleo'));
        }

        global $wpdb;
        $clientId = TokenFactory::random('ncl_');
        $row = [
            'client_id' => $clientId,
            'name' => $name,
            'redirect_uris' => (string) wp_json_encode($uris),
            'kind' => $kind,
            'created' => time(),
        ];
        $formats = ['%s', '%s', '%s', '%s', '%d'];
        // Il client confidenziale nasce con il suo hash nella stessa insert: nessun client pubblico residuo in caso di errore.
        $secretHash = $meta['secret_hash'] ?? null;
        if (is_string($secretHash) && $secretHash !== '') {
            $row['secret_hash'] = $secretHash;
            $formats[] = '%s';
        }
        $ok = $wpdb->insert(self::table('clients'), $row, $formats);
        if ($ok === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \RuntimeException(__('Client registration failed.', 'nucleo'));
        }

        return ['client_id' => $clientId, 'client_name' => $name, 'redirect_uris' => $uris];
    }

    /**
     * Autenticazione del client al token endpoint. Un client pubblico (senza secret_hash) passa sempre;
     * uno confidenziale richiede il suo secret via client_secret_post o client_secret_basic.
     */
    public function authenticateClient(string $clientId, string $method, string $secret): bool
    {
        $this->ensureSchema();
        global $wpdb;
        $hash = $wpdb->get_var($wpdb->prepare(
            'SELECT secret_hash FROM %i WHERE client_id = %s',
            self::table('clients'),
            $clientId
        ));
        if (!is_string($hash) || $hash === '') {
            return true;
        }
        if ($method !== self::AUTH_POST && $method !== self::AUTH_BASIC) {
            return false;
        }

        return $secret !== '' && wp_check_password($secret, $hash);
    }

    /**
     * Stores a client resolved from its Client ID Metadata Document (client_id = https URL).
     * Later steps (code, token, refresh) find it with findClient.
     *
     * @param array{client_id:string,client_name:string,redirect_uris:list<string>} $client
     */
    public function rememberMetadataClient(array $client): void
    {
        $this->ensureSchema();
        foreach ($client['redirect_uris'] as $uri) {
            if (!$this->isAcceptableRedirect($uri)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new \InvalidArgumentException(__('redirect_uris only accepts https, or http on localhost / 127.0.0.1.', 'nucleo'));
            }
        }
        global $wpdb;
        $wpdb->replace(self::table('clients'), [
            'client_id' => $client['client_id'],
            'name' => mb_substr($client['client_name'], 0, 200),
            'redirect_uris' => (string) wp_json_encode($client['redirect_uris']),
            'kind' => 'cimd',
            'created' => time(),
        ], ['%s', '%s', '%s', '%s', '%d']);
    }

    /** @return array{client_id:string,client_name:string,redirect_uris:list<string>,kind:string}|null */
    public function findClient(string $clientId): ?array
    {
        $this->ensureSchema();
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT client_id, name, redirect_uris, kind FROM %i WHERE client_id = %s',
            self::table('clients'),
            $clientId
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $uris = json_decode((string) $row['redirect_uris'], true);

        return [
            'client_id' => (string) $row['client_id'],
            'client_name' => (string) $row['name'],
            'redirect_uris' => is_array($uris) ? array_values($uris) : [],
            'kind' => (string) $row['kind'],
        ];
    }

    /**
     * Elimina il client e tutti i suoi token (di ogni tipo e stato).
     * Restituisce false se il client non esiste.
     */
    public function deleteClient(string $clientId): bool
    {
        if ($this->findClient($clientId) === null) {
            return false;
        }

        global $wpdb;
        $wpdb->delete(self::table('tokens'), ['client_id' => $clientId], ['%s']);
        $deleted = $wpdb->delete(self::table('clients'), ['client_id' => $clientId], ['%s']);

        return $deleted === 1;
    }

    /**
     * Crea un codice di autorizzazione monouso, valido 60 secondi.
     *
     * @param list<string> $scopes
     * @throws \InvalidArgumentException se client, redirect, challenge, resource o scope non sono validi
     */
    public function issueCode(
        string $clientId,
        int $userId,
        array $scopes,
        string $challenge,
        string $redirectUri,
        string $resource
    ): string {
        $client = $this->findClient($clientId);
        if ($client === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException(__('Unknown client.', 'nucleo'));
        }
        if (!$this->guard->isAllowedRedirect($redirectUri, $client['redirect_uris'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException(__('redirect_uri is not registered for this client.', 'nucleo'));
        }
        if (preg_match('/\A[A-Za-z0-9\-_]{43}\z/', $challenge) !== 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException(__('code_challenge is invalid.', 'nucleo'));
        }
        if ($resource === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException(__('resource is missing.', 'nucleo'));
        }

        $requested = self::normalizeScopes($scopes);
        if (count($this->allowedScopes($userId, $requested)) !== count($requested)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException(__('The user cannot grant all the requested scopes.', 'nucleo'));
        }

        $code = TokenFactory::random('ncc_');
        $this->insertToken(
            'code',
            $code,
            $clientId,
            $userId,
            $requested,
            $resource,
            '',
            time() + self::CODE_TTL,
            $challenge,
            $redirectUri
        );

        return $code;
    }

    /**
     * Scambio del codice con i token.
     *
     * @param array<string,mixed> $p client_id, code, code_verifier, redirect_uri, resource
     * @return array{access_token:string,token_type:string,expires_in:int,refresh_token:string,scope:string}|\WP_Error
     */
    public function exchangeCode(array $p): array|\WP_Error
    {
        $clientId = self::str($p, 'client_id');
        $client = $this->findClient($clientId);
        if ($client === null) {
            return self::error('invalid_client', __('Unknown client.', 'nucleo'));
        }
        if (!self::clientAllowed($client['kind'], $clientId, $client['redirect_uris'], self::allowedDomains())) {
            return self::error('invalid_client', __('The client is not in the allowed domains list.', 'nucleo'));
        }

        $row = $this->findToken(self::str($p, 'code'), 'code');
        if ($row === null || $row['client_id'] !== $clientId) {
            return self::error('invalid_grant', __('Invalid code.', 'nucleo'));
        }
        if ((int) $row['revoked'] !== 0 || (int) $row['expires'] < time()) {
            return self::error('invalid_grant', __('Code expired or already used.', 'nucleo'));
        }
        if (self::str($p, 'redirect_uri') !== $row['redirect_uri']) {
            return self::error('invalid_grant', __('redirect_uri differs from the one used for the authorization.', 'nucleo'));
        }
        if (self::str($p, 'resource') !== $row['resource']) {
            return self::error('invalid_target', __('resource differs from the one used for the authorization.', 'nucleo'));
        }
        if (!Pkce::verify(self::str($p, 'code_verifier'), $row['code_challenge'])) {
            return self::error('invalid_grant', __('code_verifier is invalid.', 'nucleo'));
        }

        $scopes = $this->allowedScopes((int) $row['user_id'], Scopes::parse($row['scopes']));
        if ($scopes === []) {
            return self::error('invalid_grant', __('The user can no longer grant these scopes.', 'nucleo'));
        }
        if (!$this->consume((int) $row['id'])) {
            return self::error('invalid_grant', __('Code expired or already used.', 'nucleo'));
        }

        return $this->issue($clientId, (int) $row['user_id'], $scopes, $row['resource'], self::newChainId());
    }

    /**
     * Rotazione del refresh token. Il riuso di uno già ruotato revoca tutta la catena.
     *
     * @param array<string,mixed> $p client_id, refresh_token, scope (facoltativo, sottoinsieme)
     * @return array{access_token:string,token_type:string,expires_in:int,refresh_token:string,scope:string}|\WP_Error
     */
    public function refresh(array $p): array|\WP_Error
    {
        $clientId = self::str($p, 'client_id');
        $client = $this->findClient($clientId);
        if ($client === null) {
            return self::error('invalid_client', __('Unknown client.', 'nucleo'));
        }
        if (!self::clientAllowed($client['kind'], $clientId, $client['redirect_uris'], self::allowedDomains())) {
            return self::error('invalid_client', __('The client is not in the allowed domains list.', 'nucleo'));
        }

        $row = $this->findToken(self::str($p, 'refresh_token'), 'refresh');
        if ($row === null || $row['client_id'] !== $clientId) {
            return self::error('invalid_grant', __('Invalid refresh token.', 'nucleo'));
        }
        if ((int) $row['revoked'] === self::REVOKED_ADMIN) {
            return self::error('invalid_grant', __('Access revoked: authorize the client again.', 'nucleo'));
        }
        if ((int) $row['revoked'] === self::REVOKED_CONSUMED) {
            $this->revokeChain($row['chain_id']);

            return self::error('invalid_grant', __('Refresh token already used: session revoked.', 'nucleo'));
        }
        if ((int) $row['expires'] < time()) {
            return self::error('invalid_grant', __('Refresh token expired.', 'nucleo'));
        }

        $original = Scopes::parse($row['scopes']);
        $asked = trim(self::str($p, 'scope'));
        $requested = $asked === '' ? $original : Scopes::parse($asked);
        if ($requested === [] || array_diff($requested, $original) !== []) {
            return self::error('invalid_scope', __('The requested scopes exceed the granted ones.', 'nucleo'));
        }
        $scopes = $this->allowedScopes((int) $row['user_id'], $requested);
        if ($scopes === []) {
            return self::error('invalid_grant', __('The user can no longer grant these scopes.', 'nucleo'));
        }

        if (!$this->consume((int) $row['id'])) {
            $this->revokeChain($row['chain_id']);

            return self::error('invalid_grant', __('Refresh token already used: session revoked.', 'nucleo'));
        }

        return $this->issue($clientId, (int) $row['user_id'], $scopes, $row['resource'], $row['chain_id']);
    }

    /** Revoca un access o un refresh token (RFC 7009): revoca l'intera catena a cui appartiene. */
    public function revoke(string $token): void
    {
        $row = $this->findToken($token, null);
        if ($row === null) {
            return;
        }
        if ($row['chain_id'] !== '') {
            $this->revokeChain($row['chain_id'], self::REVOKED_ADMIN);

            return;
        }

        global $wpdb;
        $wpdb->update(self::table('tokens'), ['revoked' => self::REVOKED_ADMIN], ['id' => (int) $row['id']], ['%d'], ['%d']);
    }

    /**
     * Valida un access token: non revocato, non scaduto, per questo MCP server, utente esistente.
     * Registra last_used del client (al più ogni 5 minuti).
     *
     * @return array{user_id:int,client_id:string,scopes:list<string>}|null
     */
    public function validateAccess(string $token): ?array
    {
        $row = $this->findToken($token, 'access');
        if ($row === null || (int) $row['revoked'] !== 0 || (int) $row['expires'] < time()) {
            return null;
        }
        if (!self::resourceMatches($row['resource'], rest_url('nucleo/v1/mcp'))) {
            return null;
        }
        $client = $this->findClient($row['client_id']);
        if ($client === null || !self::clientAllowed($client['kind'], $client['client_id'], $client['redirect_uris'], self::allowedDomains())) {
            return null;
        }
        $userId = (int) $row['user_id'];
        if (get_userdata($userId) === false) {
            return null;
        }
        $this->touchClient($row['client_id'], time());

        return ['user_id' => $userId, 'client_id' => $row['client_id'], 'scopes' => Scopes::parse($row['scopes'])];
    }

    /**
     * @param list<string> $scopes
     * @return array{access_token:string,token_type:string,expires_in:int,refresh_token:string,scope:string}
     */
    private function issue(string $clientId, int $userId, array $scopes, string $resource, string $chainId): array
    {
        $access = TokenFactory::random('nca_');
        $refresh = TokenFactory::random('ncr_');
        $now = time();
        $this->insertToken('access', $access, $clientId, $userId, $scopes, $resource, $chainId, $now + self::ACCESS_TTL);
        $this->insertToken('refresh', $refresh, $clientId, $userId, $scopes, $resource, $chainId, $now + self::REFRESH_TTL);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope' => implode(' ', $scopes),
        ];
    }

    /** @param list<string> $scopes */
    private function insertToken(
        string $kind,
        string $token,
        string $clientId,
        int $userId,
        array $scopes,
        string $resource,
        string $chainId,
        int $expires,
        string $challenge = '',
        string $redirectUri = ''
    ): void {
        global $wpdb;
        $ok = $wpdb->insert(self::table('tokens'), [
            'token_hash' => TokenFactory::hash($token),
            'kind' => $kind,
            'client_id' => $clientId,
            'user_id' => $userId,
            'scopes' => implode(' ', $scopes),
            'resource' => $resource,
            'code_challenge' => $challenge,
            'redirect_uri' => $redirectUri,
            'chain_id' => $chainId,
            'expires' => $expires,
            'revoked' => 0,
            'created' => time(),
        ], ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d']);
        if ($ok === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \RuntimeException(__('Saving the token failed.', 'nucleo'));
        }
    }

    /** @return array<string,string>|null */
    private function findToken(string $token, ?string $kind): ?array
    {
        if ($token === '') {
            return null;
        }
        $this->ensureSchema();
        global $wpdb;
        $row = $kind === null
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE token_hash = %s', self::table('tokens'), TokenFactory::hash($token)), ARRAY_A)
            : $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE token_hash = %s AND kind = %s', self::table('tokens'), TokenFactory::hash($token), $kind), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** Segna il token come usato in modo atomico: true solo se era ancora valido. */
    private function consume(int $id): bool
    {
        global $wpdb;
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET revoked = 1 WHERE id = %d AND revoked = 0',
            self::table('tokens'),
            $id
        ));

        return $n === 1;
    }

    /** Imposta lo stato sui token attivi della catena; quelli già consumati o revocati restano com'erano. */
    private function revokeChain(string $chainId, int $state = self::REVOKED_CONSUMED): void
    {
        if ($chainId === '') {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET revoked = %d WHERE chain_id = %s AND revoked = 0',
            self::table('tokens'),
            $state,
            $chainId
        ));
    }

    /** Aggiorna last_used del client solo se l'ultimo aggiornamento è più vecchio dell'intervallo. */
    private function touchClient(string $clientId, int $now): void
    {
        global $wpdb;
        $lastUsed = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT last_used FROM %i WHERE client_id = %s',
            self::table('clients'),
            $clientId
        ));
        if (!self::lastUsedDue($lastUsed, $now)) {
            return;
        }
        $wpdb->update(self::table('clients'), ['last_used' => $now], ['client_id' => $clientId], ['%d'], ['%s']);
    }

    /** Un redirect è ammesso solo se https con host, oppure http su loopback (RFC 8252). */
    private function isAcceptableRedirect(string $uri): bool
    {
        $parts = wp_parse_url($uri);
        if (!is_array($parts) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme === 'https') {
            return ($parts['host'] ?? '') !== '' && !isset($parts['user']) && !isset($parts['pass']);
        }

        return $scheme === 'http' && $this->guard->isAllowedRedirect($uri, [$uri]);
    }

    /**
     * @param list<string> $scopes
     * @return list<string>
     */
    private static function normalizeScopes(array $scopes): array
    {
        if ($scopes === [] || array_diff($scopes, Scopes::all()) !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException(__('Invalid scopes.', 'nucleo'));
        }

        return Scopes::parse(implode(' ', $scopes));
    }

    /**
     * Sottoinsieme degli scope per cui l'utente ha ancora la capability WordPress.
     *
     * @param list<string> $scopes
     * @return list<string>
     */
    private function allowedScopes(int $userId, array $scopes): array
    {
        return array_values(array_filter(
            $scopes,
            static fn (string $s): bool => user_can($userId, Scopes::capability($s))
        ));
    }

    /** Installa le tabelle se manca l'option di versione (primo uso o aggiornamento). */
    private function ensureSchema(): void
    {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }

    private static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . 'nucleo_oauth_' . $name;
    }

    private static function hostOf(string $uri): string
    {
        return strtolower((string) wp_parse_url($uri, PHP_URL_HOST));
    }

    private static function newChainId(): string
    {
        return TokenFactory::random('chn_', 16);
    }

    /** @param array<string,mixed> $p */
    private static function str(array $p, string $key): string
    {
        $value = $p[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private static function error(string $code, string $description): \WP_Error
    {
        return new \WP_Error($code, $description, ['status' => 400]);
    }
}
