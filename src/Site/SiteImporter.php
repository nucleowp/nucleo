<?php

declare(strict_types=1);

namespace Nucleo\Site;

use InvalidArgumentException;
use JsonException;
use Nucleo\Assets\RulesPublisher;
use Nucleo\Compiler\SaveOptions;
use Nucleo\Compiler\SaveResult;
use Nucleo\Compiler\TokensPublisher;
use Nucleo\Content\ContentWriter;
use Nucleo\Content\TermItems;
use Nucleo\Guide\RuleCodes;
use Nucleo\OAuth\CurrentToken;
use Nucleo\OAuth\Scopes;
use Nucleo\Sections\SectionExpander;
use Nucleo\Services;
use Nucleo\Support\Optional;
use Nucleo\Tokens\TokenSet;
use RuntimeException;
use WP_Error;
use WP_Term;
use WP_User;

/**
 * Importa i file di un sito: token, sezioni, pagine e post, layout, regole, media, menu e impostazioni.
 * Crea o aggiorna per slug e tipo di post; le pagine create in questo import risolvono i simboli successivi.
 * Un post cambiato sul sito dopo l'export non si sovrascrive, salvo $force.
 */
final class SiteImporter
{
    /** Tipi di post Nucleo accettati nel blocco wp. */
    private const POST_TYPES = ['page', 'post', 'nucleo_layout', 'nucleo_section'];

    /** Ordine di importazione delle cartelle di documenti. */
    private const ORDER = ['sections/', 'pages/', 'posts/', 'layouts/'];

    /** Tipi di content/ che hanno anche un documento Nucleo: uno slug presente lì vince (come in content-push). */
    private const DOCUMENT_FOLDERS = ['page' => 'pages/', 'post' => 'posts/'];

    /** Classe del plugin multilingua, nel package Pro: dalla Free si chiama solo tramite Optional. */
    private const MULTILINGUAL = 'Nucleo\\Integrations\\Multilingual';

    /** Codici d'errore del report: un codice diverso diventa "error" e resta in testa al messaggio. */
    public const ERROR_CODES = ['error', 'conflict', 'duplicate', 'not_json', 'invalid_document', 'quality_blocking', 'unsupported_status', 'legacy_base'];

    /** Stesso formato accettato per la lingua della traduzione in nucleo/doc-translate. */
    private const LANG_PATTERN = '/^[a-z-]{2,5}$/i';

    private int $created = 0;
    private int $updated = 0;
    /** Documenti uguali a quelli del sito: non si salvano di nuovo. */
    private int $unchanged = 0;
    /** @var list<array{file:string,message:string,code:string,guide?:string}> */
    private array $errors = [];
    /** @var array<string,int> simbolo → ID dei post salvati in questo import */
    private array $ids = [];
    /** @var array<string,string> percorso → impronta del documento al momento dell'export (site.json "base") */
    private array $base = [];
    /** @var array<string,array{url:string,alt:string,sha1:string}> nome file → voce del manifest */
    private array $manifest = [];
    /** @var array<string,string> nome file → URL dell'allegato sul sito */
    private array $mediaUrls = [];
    /** @var array<string,int> percorso → ID del post salvato da quel file */
    private array $fileIds = [];
    /** @var array<string,string> percorso → lingua dal blocco wp */
    private array $langs = [];
    /** @var list<array<string,string>> gruppi di traduzioni dal blocco wp: lingua → percorso */
    private array $groups = [];
    /** @var array<string,string> "tipo|slug|lingua" → primo file che lo usa */
    private array $claimed = [];
    /** @var array<string,array{id:int,items:list<array<mixed>>}> percorso di menus/*.json → ID del menu e voci */
    private array $menus = [];
    /** @var ?array<string,mixed> il blocco "site" di site.json, se presente */
    private ?array $site = null;
    /** @var ?array<string,string> site.json "siteBase": impronta per chiave delle impostazioni all'export; null senza il campo */
    private ?array $siteBase = null;
    /** @var ?array<string,string> nuova base delle impostazioni dopo l'import, null se non va aggiornata */
    private ?array $newSiteBase = null;
    /** @var array<string,true> simboli dei contenuti che il dry-run pubblicherebbe: valgono come trovati per menu e impostazioni */
    private array $willPublish = [];
    /** @var array<string,true> simboli dei documenti e menu che il dry-run creerebbe */
    private array $planned = [];
    /** Il documento in esame cita un simbolo che esiste solo dopo l'import (dry-run). */
    private bool $usesPlanned = false;
    /** @var array<string,string> percorso → impronta del documento salvato: la nuova base di site.json */
    private array $newBase = [];
    /** Impronta dei token di site.json, null senza il campo "tokens". */
    private ?string $localTokens = null;
    /** @var ?array<string,array<int|string,mixed>> token che il dry-run scriverebbe: i documenti si validano con questi */
    private ?array $plannedTokens = null;
    /** @var array<string,array{id:int,path:string,publish:bool}> "lingua|slug" e "lingua|percorso" → pagina di questo import (id 0: la crea il dry-run) */
    private array $pages = [];
    /** @var array<int,int> dry-run: ID di una pagina esistente → genitore che il push le darebbe */
    private array $plannedParents = [];
    /** @var array<string,string> percorso di un file di pages/ → catena del ciclo di wp.parent in cui sta */
    private array $cycles = [];
    /** @var list<array{file:string,message:string}> avvisi: il file si importa, ma qualcosa va controllato */
    private array $warnings = [];
    /** @var array<string,string> slug di una sezione non importata → motivo: le pagine che la usano falliscono con questo */
    private array $failedSections = [];

    /**
     * @param array<string,list<array{rule:string,node:string,reason:string}>> $acknowledge percorso → consensi dati da un umano
     * @param list<string> $forceFiles percorsi da sovrascrivere anche se cambiati sul sito; "site.json" vale per token e impostazioni
     */
    private function __construct(private readonly bool $dryRun, private readonly bool $force, private readonly array $acknowledge = [], private readonly array $forceFiles = [])
    {
    }

    /** Il file si sovrascrive anche se è cambiato sul sito dopo l'export: force per tutto l'import o per quel percorso. */
    private function forced(string $file): bool
    {
        return $this->force || in_array($file, $this->forceFiles, true);
    }

    /**
     * Il report porta la nuova base: "base" (percorso → impronta dei documenti salvati), "tokensBase" (impronta dei token)
     * e "siteBase" (impronta per chiave delle impostazioni del sito).
     * Chi ha fatto il push la scrive in site.json: il push successivo non va in conflitto con le proprie modifiche.
     *
     * @param array<string,string> $files percorso → contenuto JSON
     * @param bool|list<string> $force true per tutti i file, o i soli percorsi da sovrascrivere
     * @param array<string,list<array{rule:string,node:string,reason:string}>> $acknowledge percorso → consensi dati da un umano
     * @param list<string> $pending solo dry-run: simboli dei contenuti pubblicati e dei termini che lo stesso push crea prima
     *                              (content/ e terms/), per esempio "evento:congresso", "page:servizi/consulenza", "term:area/nutrizione"
     * @return array{created:int,updated:int,unchanged:int,errors:list<array{file:string,message:string,code:string,guide?:string}>,ids:array<string,int>,base:array<string,string>,tokensBase?:string,siteBase?:array<string,string>}
     */
    public static function import(array $files, bool $dryRun = false, bool|array $force = false, array $acknowledge = [], array $pending = []): array
    {
        $importer = is_array($force)
            ? new self($dryRun, false, $acknowledge, array_values(array_filter($force, 'is_string')))
            : new self($dryRun, $force, $acknowledge);
        if ($dryRun) {
            foreach ($pending as $symbol) {
                $importer->planned[$symbol] = true;
                $importer->willPublish[$symbol] = true;
            }
        }
        // Nel dry-run le sezioni dello stesso push valgono per le pagine che le inseriscono, solo dentro questo import.
        if ($dryRun) {
            Services::documents()->planning(static fn () => $importer->run($files));
        } else {
            $importer->run($files);
        }

        $report = [
            'created' => $importer->created,
            'updated' => $importer->updated,
            'unchanged' => $importer->unchanged,
            'errors' => $importer->errors,
            'warnings' => $importer->warnings,
            'ids' => $importer->ids,
            'base' => $importer->newBase,
        ];
        // La nuova base dei token vale solo se il sito ha ora gli stessi token di site.json: token saltati o in conflitto
        // lasciano la base vecchia, così il push successivo non scambia i token locali vecchi per una modifica.
        $current = self::tokensHash(get_option('nucleo_tokens', []));
        if (!$dryRun && $importer->localTokens !== null && $current === $importer->localTokens) {
            $report['tokensBase'] = $current;
        }
        if (!$dryRun && $importer->newSiteBase !== null) {
            $report['siteBase'] = $importer->newSiteBase;
        }

        return $report;
    }

    /**
     * Impronta dei token effettivi (default compresi): due insiemi con gli stessi valori hanno la stessa impronta.
     * Null se i gruppi non sono validi.
     */
    public static function tokensHash(mixed $groups): ?string
    {
        try {
            $set = TokenSet::fromArray(is_array($groups) ? $groups : []);
        } catch (InvalidArgumentException) {
            return null;
        }

        return sha1((string) wp_json_encode($set->toArray()));
    }

    /** @param array<string,string> $files */
    private function run(array $files): void
    {
        // Solo documenti JSON: il codice dei componenti (livello 4) non passa mai via HTTP.
        foreach (array_keys($files) as $path) {
            if (!str_ends_with((string) $path, '.json')) {
                $this->fail((string) $path, __('Only .json files can be imported.', 'nucleo'), 'not_json');
                unset($files[$path]);
            }
        }

        if (isset($files['site.json'])) {
            $json = $files['site.json'];
            $this->guard('site.json', fn () => $this->site($json));
        }

        if (isset($files['media/manifest.json'])) {
            $json = $files['media/manifest.json'];
            $this->guard('media/manifest.json', fn () => $this->manifest($json));
        }

        if ($this->plannedTokens === null) {
            $this->content($files);

            return;
        }

        // Dry-run con token nuovi: validazione e qualità leggono i token di site.json, senza scriverli nel database.
        $tokens = $this->plannedTokens;
        $filter = static fn (): array => $tokens;
        add_filter('pre_option_nucleo_tokens', $filter);
        Services::reset();
        try {
            $this->content($files);
        } finally {
            remove_filter('pre_option_nucleo_tokens', $filter);
            Services::reset();
        }
    }

    /**
     * Menu, documenti, voci dei menu, regole, lingue e impostazioni, in quest'ordine.
     * @param array<string,string> $files
     */
    private function content(array $files): void
    {
        // Termini ed elementi prima di tutto: i documenti e i menu possono citarli.
        $this->contentItems($files);

        // I menu esistono prima dei documenti: un riferimento menu:slug in una pagina si risolve solo se il menu c'è.
        foreach ($files as $path => $json) {
            $slug = self::menuSlug($path);
            if ($slug !== null) {
                $this->guard($path, fn () => $this->menu($path, $slug, $json));
            }
        }

        foreach (self::ORDER as $folder) {
            $paths = array_values(array_filter(
                array_keys($files),
                static fn (string $path): bool => str_starts_with($path, $folder) && str_ends_with($path, '.json'),
            ));
            if ($folder === 'sections/') {
                $paths = self::dependencyOrder($paths, $files, static fn (array $doc): array => self::sectionSlugs($doc['root'] ?? null));
            }
            if ($folder === 'pages/') {
                $paths = self::dependencyOrder($paths, $files, static fn (array $doc): array => self::parentKeys($doc), self::pageKey(...));
                $this->cycles = self::parentCycles($paths, $files);
            }
            foreach ($paths as $path) {
                $json = $files[$path];
                $errorsBefore = count($this->errors);
                $this->guard($path, fn () => $this->document($path, $json));
                if ($folder === 'sections/' && count($this->errors) > $errorsBefore) {
                    $this->failSection($json, $this->errors[count($this->errors) - 1]['message']);
                }
            }
        }

        // Le voci vanno dopo i documenti: pagine e articoli creati in questo import devono già esistere.
        foreach ($this->menus as $file => $menu) {
            $this->menuItems($file, $menu['id'], $menu['items']);
        }

        if (isset($files['rules/assets.json'])) {
            $json = $files['rules/assets.json'];
            $this->guard('rules/assets.json', fn () => $this->rules($json));
        }

        if (!$this->dryRun) {
            $this->languages();
        }

        $this->siteOptions();
    }

    /**
     * Ordina i file di una cartella: il file con lo slug che un altro usa si importa prima.
     * Sezioni: una sezione inserita in un'altra con nc/section-ref. Pagine: il genitore (wp.parent) prima dei figli.
     * Un ciclo non blocca l'ordine: lo segnala la validazione al salvataggio, o il genitore non trovato.
     * @param list<string> $paths
     * @param array<string,string> $files
     * @param callable(array<mixed>):array<string> $usesOf chiavi usate dal documento
     * @param ?callable(array<mixed>):?string $keyOf chiave del documento; senza, lo slug di wp
     * @return list<string>
     */
    private static function dependencyOrder(array $paths, array $files, callable $usesOf, ?callable $keyOf = null): array
    {
        $bySlug = [];
        $uses = [];
        foreach ($paths as $path) {
            $doc = json_decode($files[$path], true);
            $slug = is_array($doc) ? ($keyOf !== null ? $keyOf($doc) : (is_string($doc['wp']['slug'] ?? null) ? $doc['wp']['slug'] : null)) : null;
            if ($slug !== null) {
                $bySlug[$slug] = $path;
            }
            $uses[$path] = is_array($doc) ? $usesOf($doc) : [];
        }

        $seen = [];
        $ordered = [];
        $visit = static function (string $path) use (&$visit, &$seen, &$ordered, $bySlug, $uses): void {
            if (isset($seen[$path])) {
                return;
            }
            $seen[$path] = true;
            foreach ($uses[$path] as $slug) {
                if (isset($bySlug[$slug])) {
                    $visit($bySlug[$slug]);
                }
            }
            $ordered[] = $path;
        };
        foreach ($paths as $path) {
            $visit($path);
        }

        return $ordered;
    }

    /** Sezione che non si è importata (errore o conflitto): le pagine che la usano falliscono con il suo nome e il motivo. */
    private function failSection(string $json, string $reason): void
    {
        $doc = json_decode($json, true);
        $slug = is_array($doc) && is_array($doc['wp'] ?? null) ? ($doc['wp']['slug'] ?? null) : null;
        if (is_string($slug) && $slug !== '') {
            $this->failedSections[$slug] = $reason;
        }
    }

    /** @param array<mixed> $doc chiave "lingua|slug" di un file di pages/, null senza slug */
    private static function pageKey(array $doc): ?string
    {
        $wp = is_array($doc['wp'] ?? null) ? $doc['wp'] : [];
        $lang = is_string($wp['lang'] ?? null) ? $wp['lang'] : '';

        return is_string($wp['slug'] ?? null) ? $lang . '|' . $wp['slug'] : null;
    }

    /**
     * Chiavi della pagina genitore (ultimo segmento di wp.parent) nella lingua del file e senza lingua.
     * @param array<mixed> $doc
     * @return list<string>
     */
    private static function parentKeys(array $doc): array
    {
        $wp = is_array($doc['wp'] ?? null) ? $doc['wp'] : [];
        $parent = $wp['parent'] ?? null;
        if (!is_string($parent) || trim($parent, '/') === '') {
            return [];
        }
        $segments = explode('/', trim($parent, '/'));
        $slug = (string) end($segments);
        $lang = is_string($wp['lang'] ?? null) ? $wp['lang'] : '';

        return array_values(array_unique([$lang . '|' . $slug, '|' . $slug]));
    }

    /**
     * File di pages/ in un ciclo di wp.parent (a figlia di b, b figlia di a), con la catena degli slug.
     * @param list<string> $paths
     * @param array<string,string> $files
     * @return array<string,string>
     */
    private static function parentCycles(array $paths, array $files): array
    {
        $byKey = [];
        $next = [];
        foreach ($paths as $path) {
            $doc = json_decode($files[$path], true);
            $key = is_array($doc) ? self::pageKey($doc) : null;
            if ($key !== null) {
                $byKey[$key] = $path;
                $next[$path] = self::parentKeys($doc);
            }
        }
        $cycles = [];
        foreach (array_keys($next) as $start) {
            $chain = [$start];
            $current = $start;
            while (true) {
                $parent = null;
                foreach ($next[$current] as $key) {
                    if (isset($byKey[$key])) {
                        $parent = $byKey[$key];
                        break;
                    }
                }
                if ($parent === null) {
                    break;
                }
                if ($parent === $start) {
                    $slugs = array_map(static fn (string $p): string => basename($p, '.json'), [...$chain, $start]);
                    $cycles[$start] = implode(' → ', $slugs);
                    break;
                }
                if (in_array($parent, $chain, true)) {
                    break;
                }
                $chain[] = $parent;
                $current = $parent;
            }
        }

        return $cycles;
    }

    /** @return list<string> slug citati dai nodi nc/section-ref dell'albero */
    private static function sectionSlugs(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }
        $slugs = [];
        if (($node['type'] ?? null) === SectionExpander::TYPE && is_string($node['settings']['section'] ?? null)) {
            $slugs[] = $node['settings']['section'];
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            $slugs = array_merge($slugs, self::sectionSlugs($child));
        }

        return $slugs;
    }

    private function site(string $json): void
    {
        $data = $this->decode('site.json', $json);
        if ($data === null) {
            return;
        }
        $this->base = self::hashes($data['base'] ?? null);
        if (($data['schema'] ?? null) !== 1) {
            $this->fail('site.json', __('Unsupported schema.', 'nucleo'));

            return;
        }

        if (array_key_exists('site', $data)) {
            if (is_array($data['site'])) {
                $this->site = $data['site'];
                $this->siteBase = is_array($data['siteBase'] ?? null) ? self::hashes($data['siteBase']) : null;
            } else {
                $this->fail('site.json', __('"site" must be an object.', 'nucleo'));
            }
        }

        $groups = self::tokenGroups($data['tokens'] ?? []);
        if ($groups === null) {
            $this->fail('site.json', __('Invalid token groups.', 'nucleo'));

            return;
        }
        if ($groups === []) {
            $this->localTokens = array_key_exists('tokens', $data) ? self::tokensHash([]) : null;

            return;
        }

        $local = self::tokensHash($groups);
        $this->localTokens = $local;
        if ($local === null) {
            // Stesso messaggio del push vero: il dry-run valida i token senza scriverli.
            try {
                TokenSet::fromArray($groups);
            } catch (InvalidArgumentException $e) {
                $this->fail('site.json', $e->getMessage());
            }

            return;
        }

        // Confronto a tre vie con l'impronta dell'export: token non cambiati in locale non sovrascrivono quelli del sito.
        $base = is_string($data['tokensBase'] ?? null) ? $data['tokensBase'] : null;
        if ($base !== null && !$this->forced('site.json')) {
            $current = self::tokensHash(get_option('nucleo_tokens', []));
            if ($local === $base || $local === $current) {
                return;
            }
            if ($current !== $base) {
                $this->fail('site.json', __('Tokens skipped: they were changed on the site after the export (pull again, or use force to overwrite them).', 'nucleo'), 'conflict');

                return;
            }
        }
        if ($this->dryRun) {
            $this->plannedTokens = $groups;

            return;
        }

        $set = TokensPublisher::update($groups);
        if ($set instanceof WP_Error) {
            $this->fail('site.json', $set->get_error_message());
        }
    }

    private function manifest(string $json): void
    {
        $data = $this->decode('media/manifest.json', $json);
        if ($data === null) {
            return;
        }

        foreach ($data as $item) {
            if (!is_array($item) || !is_string($item['file'] ?? null) || !is_string($item['url'] ?? null) || !is_string($item['sha1'] ?? null)) {
                $this->fail('media/manifest.json', __('Each entry must have file, url and sha1 as text.', 'nucleo'));

                continue;
            }
            $this->manifest[$item['file']] = [
                'url' => $item['url'],
                'alt' => is_string($item['alt'] ?? null) ? $item['alt'] : '',
                'sha1' => $item['sha1'],
            ];
        }
    }

    /**
     * Legge un file menus/<slug>.json e, fuori dal dry-run, crea o aggiorna il menu. Le voci si scrivono dopo i documenti.
     * In dry-run si controllano solo nome e voci.
     */
    private function menu(string $file, string $slug, string $json): void
    {
        $data = $this->decode($file, $json);
        if ($data === null) {
            return;
        }

        $name = $data['name'] ?? null;
        $items = $data['items'] ?? null;
        if (!is_string($name) || trim($name) === '' || !is_array($items)) {
            $this->fail($file, __('A menu file needs a name and a list of items.', 'nucleo'));

            return;
        }

        $invalid = Menus::validate($items);
        if ($invalid !== null) {
            $this->fail($file, $invalid);

            return;
        }

        // Lista di voci con chiavi consecutive, come la vuole Menus::replaceItems.
        $list = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $this->fail($file, __('Each menu item must be an object.', 'nucleo'));

                return;
            }
            $list[] = $item;
        }

        if ($this->dryRun) {
            // Un menu nuovo esiste solo dopo l'import: i documenti che lo citano non danno "simbolo non risolto".
            if (self::findMenu($slug) === null) {
                $this->planned['menu:' . $slug] = true;
            }
            // Le voci si controllano dopo i documenti, come nel push vero.
            $this->menus[$file] = ['id' => 0, 'items' => $list];

            return;
        }

        $id = Menus::ensure($slug, $name);
        if ($id instanceof WP_Error) {
            $this->fail($file, $id->get_error_message());

            return;
        }

        $this->menus[$file] = ['id' => $id, 'items' => $list];
    }

    /** @param list<array<mixed>> $items */
    private function menuItems(string $file, int $menuId, array $items): void
    {
        $resolve = fn (string $type, string $slug): ?int => $this->resolvePost($type, $slug);
        // Dry-run: una pagina pianificata ha ID 0; il controllo delle voci vuole un ID positivo e non scrive nulla.
        $check = static fn (string $type, string $slug): ?int => ($id = $resolve($type, $slug)) === 0 ? PHP_INT_MAX : $id;
        $done = $this->dryRun ? Menus::check($items, $check, array_keys($this->willPublish)) : Menus::replaceItems($menuId, $items, $resolve);
        if ($done instanceof WP_Error) {
            $code = (string) $done->get_error_code();
            $this->fail($file, $done->get_error_message(), $code !== '' ? $code : 'error');
        }
    }

    /**
     * Impostazioni del sito da site.json "site". Si applicano per ultime, quando pagine e menu esistono.
     * Con "siteBase" si scrivono solo le chiavi cambiate in locale dopo l'export: una chiave cambiata anche sul sito
     * (per esempio con site-options-set) resta com'è sul sito e dà un conflitto, salvo force.
     */
    private function siteOptions(): void
    {
        if ($this->site === null) {
            return;
        }

        [$input, $conflicts, $siteOnly] = $this->changedOptions();
        if ($conflicts !== []) {
            /* translators: %s: comma-separated names of site settings. */
            $this->fail('site.json', sprintf(__('Site settings skipped: %s changed on the site after the export (pull again, or use force to overwrite them).', 'nucleo'), implode(', ', $conflicts)), 'conflict');
        }

        if ($this->dryRun) {
            // Stessi controlli del push vero: tipi, pagine (anche quelle create da questo import) e lingua disponibile.
            $checked = SiteOptions::check($input, fn (string $slug): ?int => $this->resolvePost('page', $slug), true);
            if ($checked instanceof WP_Error) {
                $this->fail('site.json', $checked->get_error_message(), (string) $checked->get_error_code());
            }

            return;
        }

        $result = SiteOptions::apply($input, fn (string $slug): ?int => $this->resolvePost('page', $slug));
        // La lingua si applica per ultima: se il pacchetto non si installa, le altre chiavi sono già scritte.
        $kept = $conflicts;
        if ($result instanceof WP_Error) {
            $this->fail('site.json', $result->get_error_message(), (string) $result->get_error_code());
            if ($result->get_error_code() !== 'language_unavailable') {
                return;
            }
            $kept[] = 'language';
        }

        // Nuova base: il valore riletto dal sito, come lo darebbe un pull (WordPress sanifica il titolo, la homepage
        // torna come percorso). Le chiavi in conflitto o non scritte tengono la base vecchia: il push successivo le riprova.
        // Anche le chiavi cambiate solo sul sito tengono la base vecchia: site.json locale ha ancora il valore vecchio,
        // e con la base del sito il push successivo lo scambierebbe per una modifica locale e lo riscriverebbe.
        $base = SiteOptions::hashes(SiteOptions::get());
        foreach (array_merge($kept, $siteOnly) as $key) {
            $old = $this->siteBase[$key] ?? null;
            if ($old === null) {
                unset($base[$key]);
            } else {
                $base[$key] = $old;
            }
        }
        $this->newSiteBase = $base;
    }

    /**
     * Chiavi da scrivere, chiavi in conflitto e chiavi cambiate solo sul sito. Senza siteBase si scrive tutto il blocco come prima.
     * Una chiave senza base si scrive. Una chiave uguale alla base o al sito non si scrive.
     * Con force una chiave in conflitto si scrive; una chiave cambiata solo sul sito resta com'è.
     * @return array{0:array<string,mixed>,1:list<string>,2:list<string>}
     */
    private function changedOptions(): array
    {
        $site = $this->site ?? [];
        if ($this->siteBase === null) {
            return [$site, [], []];
        }

        $current = SiteOptions::hashes(SiteOptions::get());
        $input = [];
        $conflicts = [];
        $siteOnly = [];
        foreach (SiteOptions::hashes($site) as $key => $hash) {
            $base = $this->siteBase[$key] ?? null;
            if ($base === null) {
                $input[$key] = $site[$key];
                continue;
            }
            if ($hash === ($current[$key] ?? null)) {
                continue;
            }
            if ($hash === $base) {
                $siteOnly[] = $key;
                continue;
            }
            if (($current[$key] ?? null) !== $base && !$this->forced('site.json')) {
                $conflicts[] = $key;
                continue;
            }
            $input[$key] = $site[$key];
        }

        return [$input, $conflicts, $siteOnly];
    }

    /**
     * Termini (terms/<tassonomia>.json) e poi elementi (content/<tipo>/<slug>.json), come content-push: upsert per slug,
     * in lotti da 500 termini e da 25 elementi. Senza le cartelle non succede nulla. Nel dry-run i simboli valgono per i documenti.
     * @param array<string,string> $files
     */
    private function contentItems(array $files): void
    {
        $termFiles = [];
        $itemFiles = [];
        foreach ($files as $path => $json) {
            if (preg_match('#^terms/([^/]+)\.json$#', (string) $path, $m) === 1) {
                $termFiles[$m[1]] = (string) $path;
            } elseif (preg_match('#^content/([^/]+)/([^/]+)\.json$#', (string) $path, $m) === 1) {
                $itemFiles[$m[1]][(string) $path] = $m[2];
            }
        }

        $terms = [];
        foreach ($termFiles as $taxonomy => $file) {
            $data = $this->decode($file, $files[$file]);
            if ($data === null) {
                continue;
            }
            if (!array_is_list($data)) {
                $this->fail($file, __('The file must contain a list of terms.', 'nucleo'));

                continue;
            }
            $entries = [];
            foreach ($data as $term) {
                if (!is_array($term) || !is_string($term['slug'] ?? null) || $term['slug'] === '') {
                    $this->fail($file, __('Each term must be an object with a slug.', 'nucleo'));

                    continue 2;
                }
                $entries[] = ['file' => $file, 'item' => $term];
            }
            $terms[$taxonomy] = self::parentsFirst($entries);
        }

        $items = [];
        foreach ($itemFiles as $type => $paths) {
            $entries = [];
            foreach ($paths as $file => $slug) {
                $data = $this->decode($file, $files[$file]);
                if ($data === null) {
                    continue;
                }
                if (array_is_list($data)) {
                    $this->fail($file, __('The file must contain an object.', 'nucleo'));

                    continue;
                }
                if (($data['slug'] ?? $slug) !== $slug) {
                    $this->fail($file, __('The slug in the file is not the file name.', 'nucleo'));

                    continue;
                }
                if (isset($data['type']) && $data['type'] !== $type) {
                    $this->fail($file, __('The type in the file is not the folder name.', 'nucleo'));

                    continue;
                }
                if (isset(self::DOCUMENT_FOLDERS[$type]) && isset($files[self::DOCUMENT_FOLDERS[$type] . $slug . '.json'])) {
                    $this->fail($file, __('The slug is already used by a document file of the same type.', 'nucleo'), 'duplicate');

                    continue;
                }
                $entries[] = ['file' => $file, 'item' => array_merge($data, ['slug' => $slug, 'type' => $type])];
            }
            $items[$type] = self::parentsFirst($entries);
        }

        // Dry-run: i termini e gli elementi che questo import crea valgono come trovati per i documenti e i menu.
        $pending = [];
        if ($this->dryRun) {
            foreach ($terms as $taxonomy => $entries) {
                foreach ($entries as $entry) {
                    $symbol = 'term:' . $taxonomy . '/' . $entry['item']['slug'];
                    $pending[] = $symbol;
                    $this->planned[$symbol] = true;
                    $this->willPublish[$symbol] = true;
                }
            }
            foreach ($items as $type => $entries) {
                foreach ($entries as $entry) {
                    $symbol = $type . ':' . $entry['item']['slug'];
                    $pending[] = $symbol;
                    $this->planned[$symbol] = true;
                    if (($entry['item']['status'] ?? null) === 'publish') {
                        $this->willPublish[$symbol] = true;
                    }
                }
            }
        }

        // Le immagini di media/ valgono con l'URL del manifest, come per i documenti del push.
        $media = [];
        foreach ($this->manifest as $file => $entry) {
            $media[$file] = $entry['url'];
        }

        foreach ($terms as $taxonomy => $entries) {
            foreach (array_chunk($entries, 500) as $batch) {
                $this->applyReport($batch, TermItems::set($taxonomy, array_column($batch, 'item'), $this->dryRun, $media, $pending));
            }
        }
        foreach ($items as $entries) {
            foreach (array_chunk($entries, 25) as $batch) {
                $writer = new ContentWriter(
                    dryRun: $this->dryRun,
                    media: $media,
                    pending: $pending,
                    canPublish: static fn (): bool => self::canPublish(),
                );
                $this->applyReport($batch, $writer->upsert(array_column($batch, 'item')));
            }
        }
    }

    /**
     * Conteggi di un lotto nel report; gli errori finiscono sul file dell'elemento che li ha causati.
     * @param list<array{file:string,item:array<string,mixed>}> $batch
     * @param array<string,mixed> $report
     */
    private function applyReport(array $batch, array $report): void
    {
        $this->created += (int) ($report['created'] ?? 0);
        $this->updated += (int) ($report['updated'] ?? 0);
        foreach (is_array($report['errors'] ?? null) ? $report['errors'] : [] as $error) {
            $entry = $batch[(int) ($error['index'] ?? 0)] ?? $batch[0];
            $path = is_string($error['path'] ?? null) && $error['path'] !== '' ? $error['path'] . ': ' : '';
            $this->fail($entry['file'], $path . (string) ($error['message'] ?? ''), (string) ($error['code'] ?? 'error'));
        }
    }

    /**
     * Ordina con i genitori prima dei figli: il genitore è lo slug di un altro elemento della lista. Un ciclo non si spezza.
     * @param list<array{file:string,item:array<string,mixed>}> $entries
     * @return list<array{file:string,item:array<string,mixed>}>
     */
    private static function parentsFirst(array $entries): array
    {
        $bySlug = [];
        foreach ($entries as $entry) {
            $bySlug[(string) $entry['item']['slug']] = $entry;
        }
        $done = [];
        $out = [];
        $visit = static function (string $slug, array $active) use (&$visit, &$done, &$out, $bySlug): void {
            if (!isset($bySlug[$slug]) || isset($done[$slug]) || isset($active[$slug])) {
                return;
            }
            $active[$slug] = true;
            $parent = $bySlug[$slug]['item']['parent'] ?? null;
            if (is_string($parent)) {
                $visit($parent, $active);
            }
            $done[$slug] = true;
            $out[] = $bySlug[$slug];
        };
        foreach (array_keys($bySlug) as $slug) {
            $visit((string) $slug, []);
        }

        return $out;
    }

    /**
     * Post di un documento per slug e tipo. Con più post dello stesso slug (figlie di genitori diversi, traduzioni)
     * vince quello con la lingua e il genitore del file; senza corrispondenza il documento si crea.
     * @param ?string $parentPath genitore del file, null se il file non lo indica
     */
    private static function findDocument(string $slug, string $postType, string $lang, ?string $parentPath): ?int
    {
        $ids = get_posts([
            'name' => $slug,
            'post_type' => $postType,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);
        if (count($ids) <= 1) {
            return $ids === [] ? null : (int) $ids[0];
        }

        foreach ($ids as $id) {
            $id = (int) $id;
            $postLang = Optional::call(self::MULTILINGUAL, 'postLanguage', $id);
            if ((is_string($postLang) ? $postLang : '') !== $lang) {
                continue;
            }
            if ($parentPath !== null && self::parentPathOf($id) !== $parentPath) {
                continue;
            }

            return $id;
        }

        return null;
    }

    /** Come nucleo/content-upsert: senza token OAuth vale il permesso WordPress; con token serve nucleo:publish. */
    private static function canPublish(): bool
    {
        $scopes = CurrentToken::scopes();

        return $scopes === null || in_array(Scopes::PUBLISH, $scopes, true);
    }

    /** @return ?string slug dal percorso menus/<slug>.json, null se il percorso non è di un menu */
    private static function menuSlug(string $path): ?string
    {
        return preg_match('#^menus/([^/]+)\.json$#', $path, $matches) === 1 ? $matches[1] : null;
    }

    private function rules(string $json): void
    {
        $data = $this->decode('rules/assets.json', $json);
        if ($data === null) {
            return;
        }

        $rules = [];
        foreach ($data as $rule) {
            if (!is_array($rule)) {
                $this->fail('rules/assets.json', __('Each rule must be an object.', 'nucleo'));

                return;
            }
            $rules[] = $rule;
        }
        if (!$this->dryRun) {
            RulesPublisher::publish($rules);
        }
    }

    private function document(string $file, string $json): void
    {
        $doc = $this->decode($file, $json);
        if ($doc === null) {
            return;
        }

        $wp = $doc['wp'] ?? null;
        unset($doc['wp']);
        $slug = is_array($wp) ? ($wp['slug'] ?? null) : null;
        $postType = is_array($wp) ? ($wp['postType'] ?? null) : null;
        $title = is_array($wp) ? ($wp['title'] ?? null) : null;
        $status = is_array($wp) ? ($wp['status'] ?? null) : null;
        if (
            !is_string($slug) || $slug === '' || !is_string($postType) || !in_array($postType, self::POST_TYPES, true)
            || !is_string($title) || $title === ''
        ) {
            $this->fail($file, __('Missing or invalid wp block.', 'nucleo'));

            return;
        }

        $lang = $wp['lang'] ?? null;
        $translations = $wp['translations'] ?? null;
        if (
            ($lang !== null && (!is_string($lang) || preg_match(self::LANG_PATTERN, $lang) !== 1))
            || ($translations !== null && !self::isTranslationMap($translations))
        ) {
            $this->fail($file, __('Invalid language or translations in the wp block.', 'nucleo'));

            return;
        }

        // wp.status: publish, o una bozza (draft, pending, private); assente lascia lo stato com'è.
        // future vale solo per un post già programmato sul sito (export e reimport); gli altri valori non si salvano.
        if ($status !== null && !in_array($status, ['publish', 'draft', 'pending', 'private', 'future'], true)) {
            $this->fail($file, __('Unsupported wp.status: use publish, draft, pending or private.', 'nucleo'), 'unsupported_status');

            return;
        }
        $draftStatus = in_array($status, ['draft', 'pending', 'private'], true) ? (string) $status : '';

        // Una sezione usata da questo documento non è stata importata: il motivo va detto qui, non come errore dei parametri.
        foreach (self::sectionSlugs($doc['root'] ?? null) as $section) {
            if (isset($this->failedSections[$section])) {
                $this->fail($file, sprintf(/* translators: 1: section slug, 2: reason the section was not imported. */ __('Section "%1$s" was not imported: %2$s', 'nucleo'), $section, $this->failedSections[$section]));

                return;
            }
        }

        // wp.parent: slug o percorso della pagina genitore; null = pagina di primo livello; assente = genitore invariato.
        $parentId = null;
        $parentPath = '';
        $hasParent = array_key_exists('parent', $wp);
        if ($hasParent) {
            $parent = $this->parentOf($file, $postType, $slug, is_string($lang) ? $lang : '', $status === 'publish', $wp['parent']);
            if ($parent === null) {
                return;
            }
            [$parentId, $parentPath] = $parent;
        }

        // Due file per lo stesso post (file copiato senza cambiare slug): il secondo sovrascriverebbe il primo.
        $claim = $postType . '|' . $slug . '|' . (is_string($lang) ? $lang : '');
        if (isset($this->claimed[$claim])) {
            $this->fail($file, sprintf(/* translators: 1: slug, 2: file path that already uses it. */ __('Slug "%1$s" already used by %2$s: change wp.slug in one of the two files.', 'nucleo'), $slug, $this->claimed[$claim]), 'duplicate');

            return;
        }
        $this->claimed[$claim] = $file;

        $this->usesPlanned = false;
        try {
            // Nel dry-run l'URL viene dal manifest, senza scaricare: il documento si valida come nel push vero.
            $media = $this->dryRun ? $this->plannedMediaUrl(...) : $this->mediaUrl(...);
            $doc = SymbolicRefs::mapMedia($doc, static fn (string $value): string => str_starts_with($value, 'media:') ? $media(substr($value, 6)) : $value);
            $doc = SymbolicRefs::import($doc, fn (string $symbol): ?int => $this->resolve($symbol));
        } catch (InvalidArgumentException $e) {
            $this->fail($file, $e->getMessage());

            return;
        }

        $existing = self::findDocument($slug, $postType, is_string($lang) ? $lang : '', $hasParent ? $parentPath : null);
        if ($status === 'future' && ($existing === null || get_post_status($existing) !== 'future')) {
            $this->fail($file, __('Unsupported wp.status: use publish, draft, pending or private.', 'nucleo'), 'unsupported_status');

            return;
        }
        // Revisione letta prima dei confronti: il salvataggio la richiede, così una modifica arrivata nel frattempo è un conflitto.
        $baseRevision = $existing !== null ? (Services::documents()->get($existing)['revision'] ?? null) : null;
        // L'impronta comprende il genitore: uno spostamento fatto sul sito vale come una modifica del documento.
        $localPrint = $existing !== null ? $this->localPrint($file, $doc, $hasParent ? $parentPath : self::parentPathOf($existing), $title, $status, $existing) : '';
        if ($existing !== null && !$this->forced($file) && $this->changedOnlyOnSite($file, $existing, $localPrint)) {
            // Il file locale è quello dell'ultimo pull o push: la modifica del sito resta, la base non cambia.
            $this->unchanged++;
            $this->keep($file, $existing, $postType, $slug, $lang, $translations);
            $this->newBase[$file] = $this->base[$file];
            /* translators: %s: path of the site file, e.g. pages/home.json. */
            $this->warn($file, sprintf(__('Skipped: the post changed only on the site after the last pull or push (for example with doc-patch), the local file did not. The site version stays; nucleo pull --only %s brings it into the file.', 'nucleo'), $file));

            return;
        }
        if ($existing !== null && !$this->forced($file) && $this->changedOnSite($file, $existing, $localPrint)) {
            /* translators: %s: path of the site file, e.g. pages/home.json. */
            $this->fail($file, sprintf(__('Import skipped: the post was changed on the site after the export, for example with doc-patch. To keep the site version, pull this file again (nucleo pull --only %1$s) and apply your change to it; to overwrite the site version, push with nucleo push --force %1$s (site-import force: ["%1$s"]).', 'nucleo'), $file), 'conflict');

            return;
        }

        // Base scritta prima della 0.3.5: non contiene titolo e stato, quindi una loro modifica sul sito non si distingue
        // da una locale. Se il file li cambierebbe, ci si ferma invece di riscrivere un dato forse cambiato da una persona.
        if (
            $existing !== null && !$this->forced($file) && $this->legacyBase($file)
            && (get_post_field('post_title', $existing) !== $title || (is_string($status) && get_post_status($existing) !== $status))
        ) {
            /* translators: %s: path of the site file, e.g. pages/home.json. */
            $this->fail($file, sprintf(__('Import skipped: the base of this file comes from a pull before Nucleo 0.3.5 and cannot tell whether title or status changed on the site. Title or status differ from the site: run nucleo pull --only %1$s to update the file, or push with nucleo push --force %1$s to overwrite the site.', 'nucleo'), $file), 'legacy_base');

            return;
        }

        // Documento, titolo e stato uguali al sito: nessun salvataggio. Un push dopo un pull non ricompila le pagine non toccate.
        if ($existing !== null && !$this->forced($file) && $this->sameAsSite($existing, $doc, $title, $status, $parentId)) {
            $this->unchanged++;
            $this->keep($file, $existing, $postType, $slug, $lang, $translations);

            return;
        }

        $options = new SaveOptions(
            publish: $status === 'publish',
            dryRun: $this->dryRun,
            acknowledge: $this->acknowledge[$file] ?? [],
            title: $title,
            postType: $postType,
            slug: $slug,
            parent: $this->dryRun ? null : $parentId,
            status: $draftStatus,
            baseRevision: $this->forced($file) ? null : $baseRevision,
        );

        // Dry-run: stessa validazione del salvataggio (schema e qualità bloccante), senza scrivere.
        // Un documento che cita un altro creato in questo stesso import si conta soltanto: il riferimento non esiste ancora.
        if ($this->dryRun && $existing !== null && $parentId !== null) {
            $this->plannedParents[$existing] = $parentId;
        }
        if ($this->dryRun && $this->citesPlanned()) {
            // Il riferimento a un documento creato da questo stesso import non esiste ancora: il file non si valida.
            /* translators: %s: path of the site file, e.g. pages/home.json. */
            $this->warn($file, sprintf(__('Not validated in the dry-run: %s links to content that this same push creates. The real push validates it.', 'nucleo'), $file));
            $this->countPlanned($existing, $postType, $slug, $status === 'publish', $parentPath, $lang);
            if ($postType === 'nucleo_section') {
                Services::documents()->plan($slug, $doc, $existing ?? 0);
            }

            return;
        }

        $result = $existing === null
            ? Services::documents()->create($doc, $options)
            : Services::documents()->save($existing, $doc, $options);
        if (!$result->ok) {
            $this->failSave($file, $result);

            return;
        }

        if ($this->dryRun) {
            $this->countPlanned($existing, $postType, $slug, $status === 'publish', $parentPath, $lang);

            return;
        }

        if ($existing === null) {
            $this->created++;
            if (get_post_field('post_name', $result->postId) !== $slug) {
                wp_update_post(['ID' => $result->postId, 'post_name' => $slug]);
            }
        } else {
            $this->updated++;
        }

        $this->fileIds[$file] = (int) $result->postId;
        if (is_string($lang)) {
            $this->langs[$file] = $lang;
        }
        if (is_array($translations)) {
            $this->groups[] = $translations;
        }

        $symbol = self::symbolOf($postType, $slug);
        if ($symbol !== null) {
            $this->ids[$symbol] = $result->postId;
        }
        $this->rememberPage($postType, $slug, (int) $result->postId, $lang);

        $saved = Services::documents()->get((int) $result->postId);
        if ($saved !== null) {
            $this->newBase[$file] = self::postPrint((int) $result->postId, $saved['document']);
        }
    }

    /** @param array<string,mixed> $doc @param ?int $parentId genitore da wp.parent, null se il file non lo indica */
    private function sameAsSite(int $postId, array $doc, string $title, mixed $status, ?int $parentId = null): bool
    {
        $found = Services::documents()->get($postId);
        if ($found === null || SiteExporter::hash($found['document']) !== SiteExporter::hash($doc)) {
            return false;
        }
        if ($parentId !== null && (int) get_post_field('post_parent', $postId) !== $parentId) {
            return false;
        }

        return get_post_field('post_title', $postId) === $title && (!is_string($status) || get_post_status($postId) === $status);
    }

    /**
     * Documento saltato perché uguale al sito: resta nella nuova base, risolve i simboli e partecipa alle traduzioni.
     */
    private function keep(string $file, int $postId, string $postType, string $slug, mixed $lang, mixed $translations): void
    {
        $saved = Services::documents()->get($postId);
        if ($saved !== null) {
            $this->newBase[$file] = self::postPrint($postId, $saved['document']);
        }
        $symbol = self::symbolOf($postType, $slug);
        if ($symbol !== null && !$this->dryRun) {
            $this->ids[$symbol] = $postId;
        }
        $this->rememberPage($postType, $slug, $postId, $lang);
        if ($this->dryRun) {
            return;
        }
        $this->fileIds[$file] = $postId;
        if (is_string($lang)) {
            $this->langs[$file] = $lang;
        }
        if (is_array($translations)) {
            $this->groups[] = $translations;
        }
    }

    /** Il documento appena letto cita un simbolo che il dry-run non ha ancora creato (lo imposta resolve()). */
    private function citesPlanned(): bool
    {
        return $this->usesPlanned;
    }

    /**
     * Dry-run: conta il documento e ne registra il simbolo, così i documenti successivi lo trovano.
     * Una pagina figlia vale anche col percorso ("azienda/controllo-qualita"), come nelle voci dei menu.
     */
    private function countPlanned(?int $existing, string $postType, string $slug, bool $publish, string $parentPath = '', mixed $lang = null): void
    {
        $existing === null ? $this->created++ : $this->updated++;
        $symbols = [self::symbolOf($postType, $slug)];
        if ($postType === 'page') {
            $path = $parentPath === '' ? $slug : $parentPath . '/' . $slug;
            $this->addPage($lang, $slug, $path, $existing ?? 0, $publish);
            $symbols[] = 'page:' . $path;
        }
        foreach (array_unique(array_filter($symbols)) as $symbol) {
            if ($publish) {
                $this->willPublish[$symbol] = true;
            }
            if ($existing === null) {
                $this->planned[$symbol] = true;
            }
        }
    }

    /** Pagina salvata o invariata in questo import: la trovano i figli (wp.parent) e i simboli page:<percorso>. */
    private function rememberPage(string $postType, string $slug, int $postId, mixed $lang = null): void
    {
        if ($postType !== 'page' || $postId <= 0) {
            return;
        }
        $path = get_page_uri($postId);
        $path = is_string($path) && $path !== '' ? $path : $slug;
        $this->addPage($lang, $slug, $path, $postId, get_post_status($postId) === 'publish');
        if (!$this->dryRun) {
            $this->ids['page:' . $path] = $postId;
        }
    }

    private function addPage(mixed $lang, string $slug, string $path, int $id, bool $publish): void
    {
        $prefix = is_string($lang) ? $lang : '';
        $entry = ['id' => $id, 'path' => $path, 'publish' => $publish];
        $this->pages[$prefix . '|' . $slug] = $this->pages[$prefix . '|' . $path] = $entry;
    }

    /**
     * Genitore da wp.parent: [ID, percorso]. ID 0 e percorso vuoto per null (primo livello); ID 0 per una pagina
     * che il dry-run creerebbe. Null se il valore non è valido, la pagina non c'è o forma un ciclo: l'errore è già registrato.
     * @return array{0:int,1:string}|null
     */
    private function parentOf(string $file, string $postType, string $slug, string $lang, bool $publish, mixed $parent): ?array
    {
        if ($postType !== 'page') {
            $this->fail($file, __('wp.parent is only for pages (postType page).', 'nucleo'));

            return null;
        }
        if ($parent === null) {
            return [0, ''];
        }
        $value = is_string($parent) ? trim($parent, '/') : '';
        if ($value === '') {
            $this->fail($file, __('wp.parent must be the slug or the path of the parent page ("azienda", "chi-siamo/azienda"), or null.', 'nucleo'));

            return null;
        }
        if (isset($this->cycles[$file])) {
            /* translators: %s: chain of page slugs, e.g. "a → b → a". */
            $this->fail($file, sprintf(__('wp.parent forms a cycle: %s. Give one of these pages another parent, or null.', 'nucleo'), $this->cycles[$file]));

            return null;
        }

        $found = $this->pages[$lang . '|' . $value] ?? $this->pages['|' . $value] ?? self::findPage($value, $lang);
        if ($found === null) {
            /* translators: %s: slug or path of the parent page. */
            $this->fail($file, sprintf(__('Parent page not found: "%s" (wp.parent). Use the slug or the path of a page of the site, or add its file to pages/: the parent is imported before its children.', 'nucleo'), $value));

            return null;
        }

        // Ciclo con le pagine del sito: si risale la catena dei genitori, con quelli che il dry-run cambierebbe.
        $self = self::findPost($slug, 'page');
        $current = $found['id'];
        for ($step = 0; $self !== null && $current > 0 && $step < 100; $step++) {
            if ($current === $self) {
                /* translators: %s: slug or path of the parent page. */
                $this->fail($file, sprintf(__('wp.parent forms a cycle: "%s" is this page or one of its descendants. Give the page another parent, or null.', 'nucleo'), $value));

                return null;
            }
            $current = $this->plannedParents[$current] ?? (int) get_post_field('post_parent', $current);
        }

        if ($publish && !$found['publish']) {
            /* translators: %s: path of the parent page. */
            $this->warn($file, sprintf(__('The parent page "%s" is not published: the child page is online under its path, the parent page is not.', 'nucleo'), $found['path']));
        }

        return [$found['id'], $found['path']];
    }

    /**
     * Pagina (solo post type page, non nel cestino) per slug o percorso. Con la lingua, preferisce la pagina di quella lingua.
     * @return array{id:int,path:string,publish:bool}|null
     */
    private static function findPage(string $value, string $lang = ''): ?array
    {
        $segments = explode('/', $value);
        $ids = get_posts([
            'name' => (string) end($segments),
            'post_type' => 'page',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);
        $found = null;
        foreach ($ids as $id) {
            $id = (int) $id;
            $path = (string) get_page_uri($id);
            if (count($segments) > 1 && $path !== $value) {
                continue;
            }
            $entry = ['id' => $id, 'path' => $path !== '' ? $path : $value, 'publish' => get_post_status($id) === 'publish'];
            if ($lang === '' || Optional::call(self::MULTILINGUAL, 'postLanguage', $id) === $lang) {
                return $entry;
            }
            $found ??= $entry;
        }

        return $found;
    }

    /** Percorso del genitore di una pagina ("chi-siamo/azienda"); vuoto per una pagina di primo livello o un altro tipo. */
    private static function parentPathOf(int $postId): string
    {
        $parent = get_post_type($postId) === 'page' ? (int) get_post_field('post_parent', $postId) : 0;
        $path = $parent > 0 ? get_page_uri($parent) : '';

        return is_string($path) ? $path : '';
    }

    private function warn(string $file, string $message): void
    {
        $this->warnings[] = ['file' => $file, 'message' => $message];
    }

    /**
     * Errore di un salvataggio: codice del servizio documenti, primo errore di schema con percorso e valore,
     * regole e nodi dei problemi bloccanti da correggere o da far riconoscere a un umano.
     */
    private function failSave(string $file, SaveResult $result): void
    {
        $code = (string) ($result->error['code'] ?? 'error');
        $message = (string) ($result->error['message'] ?? __('Saving failed.', 'nucleo'));
        $first = $result->errors[0] ?? null;
        if (is_array($first) && isset($first['path'], $first['message'])) {
            $got = isset($first['got']) && is_scalar($first['got']) ? sprintf(/* translators: %s: value found in the file. */ __(' (found: %s)', 'nucleo'), $first['got']) : '';
            $more = count($result->errors) > 1
                ? sprintf(/* translators: %d: number of additional errors. */ _n(' and %d more error', ' and %d more errors', count($result->errors) - 1, 'nucleo'), count($result->errors) - 1)
                : '';
            $allowed = is_array($first['allowed'] ?? null) && $first['allowed'] !== []
            ? sprintf(/* translators: %s: comma-separated list of allowed values. */ __(' (allowed: %s)', 'nucleo'), implode(', ', array_filter($first['allowed'], 'is_scalar')))
            : '';
            $fix = is_string($first['fix'] ?? null) && $first['fix'] !== '' ? ' ' . $first['fix'] : '';
            $message .= ' ' . $first['path'] . ': ' . $first['message'] . $got . $allowed . $more . '.' . $fix;
        }
        if ($code === 'quality_blocking') {
            $blocking = [];
            foreach (is_array($result->quality['issues'] ?? null) ? $result->quality['issues'] : [] as $issue) {
                if (is_array($issue) && (($issue['severity'] ?? null) === 'error' || ($issue['blocksPublish'] ?? false) === true)) {
                    $nodes = array_map(static fn (mixed $n): string => is_array($n) ? (string) ($n['id'] ?? '') : (string) $n, is_array($issue['nodes'] ?? null) ? $issue['nodes'] : []);
                    $blocking[] = (string) ($issue['rule'] ?? '') . '@' . implode(',', array_filter($nodes));
                }
            }
            if ($blocking !== []) {
                $message .= ' ' . implode('; ', array_unique($blocking)) . '.';
            }
        }
        $this->fail($file, $message, $code);
    }

    /** URL del manifest per il dry-run; un file assente dal manifest dà lo stesso errore del push vero. */
    private function plannedMediaUrl(string $file): string
    {
        if (isset($this->manifest[$file])) {
            return $this->manifest[$file]['url'];
        }
        // Come nel push: l'allegato in libreria con lo stesso nome file, senza scaricare nulla.
        $id = self::libraryAttachment($file);
        $url = $id === null ? false : wp_get_attachment_url($id);
        if ($url === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: file name. */ __('Media not found in the manifest or in the library: %s', 'nucleo'), $file));
        }

        return $url;
    }

    /** @return int|null ID di un allegato in libreria con lo stesso nome file, qualunque sia il suo sha1 */
    private static function libraryAttachment(string $file): ?int
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_query' => [['key' => '_wp_attached_file', 'value' => $file, 'compare' => 'LIKE']],
        ]);

        foreach ($ids as $id) {
            $path = (wp_get_original_image_path((int) $id) ?: get_attached_file((int) $id));
            if (is_string($path) && basename($path) === $file) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Imposta la lingua dei post importati e collega le traduzioni. Si fa a import finito, quando tutti i post esistono.
     * Un percorso non importato si salta; senza plugin multilingua le chiamate non fanno nulla.
     */
    private function languages(): void
    {
        foreach ($this->langs as $file => $lang) {
            if (isset($this->fileIds[$file])) {
                Optional::call(self::MULTILINGUAL, 'setLanguage', $this->fileIds[$file], $lang);
            }
        }

        foreach ($this->groups as $group) {
            $byLang = [];
            foreach ($group as $lang => $path) {
                if (isset($this->fileIds[$path])) {
                    $byLang[$lang] = $this->fileIds[$path];
                }
            }
            if (count($byLang) > 1) {
                Optional::call(self::MULTILINGUAL, 'link', $byLang);
            }
        }
    }

    /**
     * Il post è cambiato sul sito dopo l'export (hCur diverso dalla base) e l'import lo cambierebbe ancora (diverso da hNew).
     * Un file senza voce in base non entra nel controllo.
     * @param string $local impronta del documento che l'import scriverebbe, genitore compreso (SiteExporter::fingerprint)
     */
    private function changedOnSite(string $file, int $postId, string $local): bool
    {
        $base = $this->base[$file] ?? null;
        $current = $this->currentPrint($file, $postId);
        if ($base === null || $current === null) {
            return false;
        }

        return $current !== $base && $current !== $local;
    }

    /** Il post è cambiato sul sito dopo la base, il file locale no: come per le impostazioni, vince il sito. */
    private function changedOnlyOnSite(string $file, int $postId, string $local): bool
    {
        $base = $this->base[$file] ?? null;
        $current = $this->currentPrint($file, $postId);

        return $base !== null && $current !== null && $local === $base && $current !== $base;
    }

    /** La base del file è in v1 (senza prefisso 'v2:', scritta prima di titolo e stato nell'impronta). */
    private function legacyBase(string $file): bool
    {
        $base = $this->base[$file] ?? null;

        return is_string($base) && !str_starts_with($base, 'v2:');
    }

    /**
     * Impronta del documento che l'import scriverebbe, nello stesso formato della base del file.
     * Senza wp.status il titolo e lo stato restano quelli del post.
     * @param array<string,mixed> $doc
     */
    private function localPrint(string $file, array $doc, string $parentPath, string $title, mixed $status, int $postId): string
    {
        if ($this->legacyBase($file)) {
            return SiteExporter::fingerprint($doc, $parentPath);
        }
        $localStatus = is_string($status) ? $status : (string) get_post_status($postId);

        return SiteExporter::fingerprint($doc, $parentPath, $title, $localStatus);
    }

    /**
     * Impronta v2 di un post salvato: documento, genitore, titolo e stato sul sito.
     * @param array<string,mixed> $document
     */
    private static function postPrint(int $postId, array $document): string
    {
        return SiteExporter::fingerprint($document, self::parentPathOf($postId), (string) get_post_field('post_title', $postId), (string) get_post_status($postId));
    }

    /** Impronta del post sul sito, nel formato della base del file; null se il post non ha un documento Nucleo. */
    private function currentPrint(string $file, int $postId): ?string
    {
        $found = Services::documents()->get($postId);
        if ($found === null) {
            return null;
        }

        return $this->legacyBase($file) ? SiteExporter::fingerprint($found['document'], self::parentPathOf($postId)) : self::postPrint($postId, $found['document']);
    }

    /** URL di un allegato per nome file: quello in libreria con lo stesso sha1, quello indicato dalla voce se è già del sito, o uno scaricato dal manifest. */
    private function mediaUrl(string $file): string
    {
        if (isset($this->mediaUrls[$file])) {
            return $this->mediaUrls[$file];
        }

        $entry = $this->manifest[$file] ?? null;
        // Fuori dal manifest vale l'allegato in libreria con lo stesso nome file.
        $id = $entry === null
            ? self::libraryAttachment($file)
            : (self::findAttachment($file, $entry['sha1']) ?? self::localAttachment($entry) ?? self::sideload($file, $entry));
        if ($id === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: file name. */ __('Media not found in the manifest or in the library: %s', 'nucleo'), $file));
        }

        $url = wp_get_attachment_url($id);
        if ($url === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: file name. */ __('Media URL unavailable: %s', 'nucleo'), $file));
        }

        $this->mediaUrls[$file] = $url;

        return $url;
    }

    /** @return int|null ID di un allegato con lo stesso nome file e lo stesso sha1 */
    private static function findAttachment(string $file, string $sha1): ?int
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_query' => [['key' => '_wp_attached_file', 'value' => $file, 'compare' => 'LIKE']],
        ]);

        foreach ($ids as $id) {
            $path = (wp_get_original_image_path((int) $id) ?: get_attached_file((int) $id));
            if (is_string($path) && basename($path) === $file && is_file($path) && sha1_file($path) === $sha1) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Allegato del sito a cui punta l'URL della voce, se il file ha lo stesso sha1: così un upload della CLI non si scarica di nuovo.
     * @param array{url:string,alt:string,sha1:string} $entry
     * @return int|null ID dell'allegato, null se l'URL non è di questo sito o il file non corrisponde
     */
    private static function localAttachment(array $entry): ?int
    {
        $id = attachment_url_to_postid($entry['url']);
        if ($id <= 0) {
            return null;
        }

        $path = (wp_get_original_image_path($id) ?: get_attached_file($id));

        return is_string($path) && is_file($path) && sha1_file($path) === $entry['sha1'] ? $id : null;
    }

    /**
     * @param array{url:string,alt:string,sha1:string} $entry
     * @return int ID del nuovo allegato
     */
    private static function sideload(string $file, array $entry): int
    {
        if (wp_http_validate_url($entry['url']) === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: file name. */ __('Invalid media URL: %s', 'nucleo'), $file));
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $id = media_sideload_image($entry['url'], 0, $entry['alt'], 'id');
        if ($id instanceof WP_Error) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: 1: file name, 2: error message. */ __('Media not downloaded (%1$s): %2$s', 'nucleo'), $file, $id->get_error_message()));
        }

        return (int) $id;
    }

    /** Risolve un simbolo con i post già salvati o creati in questo import. */
    private function resolve(string $symbol): ?int
    {
        if (isset($this->ids[$symbol])) {
            return $this->ids[$symbol];
        }

        $found = $this->find($symbol);
        if ($found === null && $this->dryRun && isset($this->planned[$symbol])) {
            $this->usesPlanned = true;

            return 0;
        }

        return $found;
    }

    /** Post, termine, autore o menu già presente sul sito per un simbolo. */
    private function find(string $symbol): ?int
    {

        $parts = explode(':', $symbol, 2);
        if (count($parts) !== 2 || $parts[1] === '') {
            return null;
        }
        [$kind, $rest] = $parts;

        return match ($kind) {
            'page' => self::findPage($rest)['id'] ?? null,
            'post' => self::findPost($rest, 'post'),
            'section' => self::findPost($rest, 'nucleo_section'),
            'term' => self::findTerm($rest),
            'author' => self::findAuthor($rest),
            'menu' => self::findMenu($rest),
            default => null,
        };
    }

    /**
     * Contenuto per i menu e le impostazioni: quello creato in questo import, o uno pubblicato con lo slug
     * (per i tipi gerarchici anche il percorso, "servizi/consulenza").
     * @param string $type tipo di post: 'page', 'post' o un tipo personalizzato
     */
    private function resolvePost(string $type, string $slug): ?int
    {
        $symbol = $type . ':' . $slug;
        // Un post di questo import vale solo se pubblicato: un menu o la homepage non puntano a una bozza.
        $id = $this->ids[$symbol] ?? null;
        if ($id !== null && get_post_status($id) === 'publish') {
            return $id;
        }
        $found = Menus::findPost($type, $slug)?->ID;

        // Dry-run: il contenuto che questo push pubblicherebbe vale come trovato (ID 0).
        return $found ?? ($this->dryRun && isset($this->willPublish[$symbol]) ? 0 : null);
    }

    private static function findPost(string $slug, string $postType, string $status = 'any'): ?int
    {
        $ids = get_posts([
            'name' => $slug,
            'post_type' => $postType,
            'post_status' => $status,
            'numberposts' => 1,
            'fields' => 'ids',
        ]);

        return $ids === [] ? null : (int) $ids[0];
    }

    /** @param string $rest "tassonomia:slug" */
    private static function findTerm(string $rest): ?int
    {
        $parts = explode(':', $rest, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $term = get_term_by('slug', $parts[1], $parts[0]);

        return $term instanceof WP_Term ? (int) $term->term_id : null;
    }

    private static function findAuthor(string $login): ?int
    {
        $user = get_user_by('login', $login);

        return $user instanceof WP_User ? (int) $user->ID : null;
    }

    private static function findMenu(string $slug): ?int
    {
        $menu = wp_get_nav_menu_object($slug);

        return $menu instanceof WP_Term ? (int) $menu->term_id : null;
    }

    private static function symbolOf(string $postType, string $slug): ?string
    {
        return match ($postType) {
            'page' => 'page:' . $slug,
            'post' => 'post:' . $slug,
            'nucleo_section' => 'section:' . $slug,
            default => null,
        };
    }

    /** Mappa lingua → percorso: chiavi lingua valide, valori percorsi non vuoti. */
    private static function isTranslationMap(mixed $raw): bool
    {
        if (!is_array($raw)) {
            return false;
        }

        foreach ($raw as $lang => $path) {
            if (!is_string($lang) || preg_match(self::LANG_PATTERN, $lang) !== 1 || !is_string($path) || $path === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string,string> percorso → impronta, solo le voci di testo
     */
    private static function hashes(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $hashes = [];
        foreach ($raw as $path => $hash) {
            if (is_string($path) && is_string($hash)) {
                $hashes[$path] = $hash;
            }
        }

        return $hashes;
    }

    /**
     * @param array<mixed> $raw
     * @return array<string,array<int|string,mixed>>|null
     */
    private static function tokenGroups(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $groups = [];
        foreach ($raw as $name => $values) {
            if (!is_string($name) || !is_array($values)) {
                return null;
            }
            $groups[$name] = $values;
        }

        return $groups;
    }

    /** @return array<mixed>|null null se il JSON non è un oggetto o un elenco (errore già registrato) */
    private function decode(string $file, string $json): ?array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->fail($file, sprintf(/* translators: %s: JSON parser error message. */ __('Invalid JSON: %s', 'nucleo'), $e->getMessage()));

            return null;
        }

        if (!is_array($data)) {
            $this->fail($file, __('The file does not contain a JSON object.', 'nucleo'));

            return null;
        }

        return $data;
    }

    /** Un errore su un file non ferma l'import degli altri. */
    private function guard(string $file, callable $step): void
    {
        try {
            $step();
        } catch (InvalidArgumentException | RuntimeException $e) {
            $this->fail($file, $e->getMessage());
        }
    }

    private function fail(string $file, string $message, string $code = 'error'): void
    {
        // Codici di altri servizi (menu_target_missing, page_not_found…) restano leggibili nel messaggio.
        if (!in_array($code, self::ERROR_CODES, true)) {
            $message = $code . ': ' . $message;
            $code = 'error';
        }
        $error = ['file' => $file, 'message' => $message, 'code' => $code];
        // Un file PHP nel push: il codice dei componenti non passa mai da qui (R4.3).
        if ($code === 'not_json' && str_ends_with(strtolower($file), '.php')) {
            $error['guide'] = (string) RuleCodes::forError('component_code');
        }
        $this->errors[] = $error;
    }
}
