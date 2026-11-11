<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

defined('ABSPATH') || exit;

use Nucleo\Bindings\WpBindingSource;
use Nucleo\Blocks\Registry;
use Nucleo\Layout\LayoutConditions;
use Nucleo\Layout\PostTypes;
use Nucleo\Quality\Issue;
use Nucleo\Quality\PageComposer;
use Nucleo\Quality\QualityEngine;
use Nucleo\Quality\QualityReport;
use Nucleo\Schema\DocumentValidator;
use Nucleo\Schema\Migrator;
use Nucleo\Schema\Normalizer;
use Nucleo\Schema\PatchEngine;
use Nucleo\Schema\PatchException;
use Nucleo\Schema\ValidationError;
use Nucleo\Seo\StructuredData;
use Nucleo\Sections\ErrorMapper;
use Nucleo\Sections\Expansion;
use Nucleo\Sections\SectionExpander;
use Nucleo\Services;
use Nucleo\Site\SymbolicRefs;
use Nucleo\Quality\Severity;
use Nucleo\Storage\AuditLog;
use Nucleo\Storage\DocumentLock;
use Nucleo\Storage\DocumentRepository;
use RuntimeException;

/**
 * Pipeline di salvataggio: normalizza, valida, controlla la qualità, compila, scrive il post.
 * postId 0 indica un documento non ancora salvato (create).
 */
final class DocumentService
{
    private const DEP_META = '_nucleo_dep';

    /** Bersagli dei link per riferimento: LinkTargets::key → "link:post:<id>" o "link:term:<id>". */
    private const LINKS_META = '_nucleo_links';

    /** Errori dell'ultima ricompilazione fallita: il post tiene HTML e CSS precedenti. */
    public const STALE_META = '_nucleo_stale';
    /** Riconoscimenti (acknowledge) ancora validi: valgono per i salvataggi successivi e per la ricompilazione. */
    public const ACK_META = '_nucleo_ack';
    public const QUALITY_STALE_META = '_nucleo_stale_quality';
    /** Esito dell'ultimo controllo di qualità riuscito: errori, avvisi, punteggi e istante del controllo. */
    public const QUALITY_META = '_nucleo_quality';

    /** Regole di pagina non applicate a layout e sezioni: le verifica la pagina composta. */
    private const PAGE_ONLY_RULES = ['seo.h1.missing', 'a11y.landmark.missing', 'seo.content.thin'];

    /** Regole non applicate alle sezioni: i parametri veri arrivano solo nelle pagine che le usano. */
    private const NOT_FOR_SECTIONS = ['content.button.inert', 'form.action.consequential'];

    private static bool $writing = false;

    /**
     * Sezioni del dry-run in corso (push --dry-run), per slug: i documenti successivi dello stesso dry-run
     * le espandono come nel push vero, anche se il post non esiste ancora. Null fuori da planning().
     * @var array<string,array{id:int,doc:array<string,mixed>}>|null
     */
    private ?array $planned = null;

    /** True mentre si valida un salvataggio con dryRun: solo allora findSection legge $planned. */
    private bool $inDryRun = false;

    /**
     * Toglie le regole di pagina dal report di layout, sezioni e popup: le verifica la pagina composta.
     * @param array<string,mixed> $doc
     */
    public static function scopeReport(array $doc, QualityReport $report): QualityReport
    {
        $kind = $doc['kind'] ?? null;
        if (!in_array($kind, ['layout', 'section', 'popup'], true)) {
            return $report;
        }
        $skip = $kind === 'section' ? [...self::PAGE_ONLY_RULES, ...self::NOT_FOR_SECTIONS] : self::PAGE_ONLY_RULES;

        return new QualityReport(
            array_values(array_filter(
                $report->issues,
                static fn (object $issue): bool => !in_array($issue->rule, $skip, true),
            )),
            $report->acknowledged,
        );
    }

    public function __construct(
        private readonly Compiler $compiler,
        private readonly DocumentValidator $validator,
        private readonly Normalizer $normalizer,
        private readonly PatchEngine $patcher,
        private readonly PageComposer $composer,
        private readonly QualityEngine $quality,
        private readonly DocumentRepository $repository,
    ) {
    }

    /**
     * Esegue un dry-run di più documenti (site-import): le sezioni validate o contate durante $fn valgono
     * per i documenti successivi dello stesso dry-run. Alla fine si dimenticano.
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function planning(callable $fn): mixed
    {
        $previous = $this->planned;
        $this->planned = [];
        try {
            return $fn();
        } finally {
            $this->planned = $previous;
        }
    }

    /**
     * Registra una sezione del dry-run in corso; fuori da planning() non fa nulla.
     * @param array<string,mixed> $doc documento grezzo, come si salverebbe
     */
    public function plan(string $slug, array $doc, int $postId = 0): void
    {
        if ($this->planned !== null) {
            $this->planned[$slug] = ['id' => $postId, 'doc' => $doc];
        }
    }

    /** True durante una scrittura di Nucleo: i listener di invalidazione la ignorano. */
    public static function writing(): bool
    {
        return self::$writing;
    }

    /** @param array<string,mixed> $doc */
    public function create(array $doc, SaveOptions $o): SaveResult
    {
        $result = self::scoped(fn (): SaveResult => $this->process(0, $doc, $o, 0));
        // Static bindings read the post (title, meta): it exists only now, so compile once more.
        if ($result->ok && $result->postId > 0 && !$o->dryRun && str_contains((string) wp_json_encode($doc), '"bind"')) {
            $this->recompile($result->postId);
        }

        return $result;
    }

    /**
     * Aggancia il primo documento Nucleo a un contenuto esistente: tipo, titolo e stato restano quelli del post.
     * @param array<string,mixed> $doc
     */
    public function start(int $postId, array $doc, SaveOptions $o): SaveResult
    {
        return $this->locked($postId, $o->dryRun, fn (): SaveResult => self::scoped(function () use ($postId, $doc, $o): SaveResult {
            $found = $this->repository->find($postId);
            if ($found === null) {
                return $this->failure(0, 0, 'not_found', __('Content not found.', 'nucleo'));
            }
            if ($found['document'] !== null) {
                return $this->failure($postId, 0, 'exists', __('This content already has a Nucleo document.', 'nucleo'));
            }

            $content = (string) get_post_field('post_content', $postId);
            if (trim($content) !== '' && is_array($doc['root'] ?? null) && ($doc['root']['children'] ?? []) === []) {
                $doc['root']['children'] = self::textNodes($content);
            }
            $result = $this->process($postId, $doc, $o, 0);
            // Copia del testo originale: alcuni tipi di contenuto non hanno revisioni.
            if ($result->ok && !$o->dryRun && trim($content) !== '') {
                update_post_meta($postId, '_nucleo_wp_content', wp_slash($content));
            }

            return $result;
        }));
    }

    /**
     * Paragrafi, titoli e voci di elenco del testo WordPress come blocchi nc/text. Restano grassetto, corsivo e link;
     * immagini e altri elementi si perdono (l'originale resta in _nucleo_wp_content).
     * @return list<array<string,mixed>>
     */
    private static function textNodes(string $content): array
    {
        $html = wpautop(preg_replace('/<!--.*?-->/s', '', $content) ?? '');
        preg_match_all('#<(p|h[1-6]|li)\b[^>]*>(.*?)</\1>#is', $html, $matches);
        $allowed = ['a' => ['href' => true], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'br' => []];
        $nodes = [];
        foreach ($matches[2] as $i => $inner) {
            $text = trim(wp_kses($inner, $allowed));
            if ($text !== '' && count($nodes) < 200) {
                $nodes[] = ['id' => 't' . ($i + 1), 'type' => 'nc/text', 'content' => ['html' => $text]];
            }
        }

        return $nodes;
    }

    /** @param array<string,mixed> $doc */
    public function save(int $postId, array $doc, SaveOptions $o): SaveResult
    {
        return $this->locked($postId, $o->dryRun, fn (): SaveResult => self::scoped(function () use ($postId, $doc, $o): SaveResult {
            $found = $this->repository->find($postId);
            if ($found === null) {
                return $this->failure(0, 0, 'not_found', __('Post not found.', 'nucleo'));
            }
            if ($this->conflicts($found['revision'], $o)) {
                return $this->conflict($postId, $found['revision']);
            }

            return $this->process($postId, $doc, $o, $found['revision']);
        }));
    }

    /** @param list<array<string,mixed>> $ops */
    public function patch(int $postId, array $ops, SaveOptions $o): SaveResult
    {
        return $this->locked($postId, $o->dryRun, fn (): SaveResult => self::scoped(function () use ($postId, $ops, $o): SaveResult {
            $found = $this->repository->find($postId);
            if ($found === null) {
                return $this->failure(0, 0, 'not_found', __('Post not found.', 'nucleo'));
            }
            if ($this->conflicts($found['revision'], $o)) {
                return $this->conflict($postId, $found['revision']);
            }
            if ($found['document'] === null) {
                return $this->failure($postId, $found['revision'], 'patch_failed', __('The post does not contain a Nucleo document.', 'nucleo'));
            }

            try {
                $doc = $this->patcher->apply($found['document'], $ops);
            } catch (PatchException $e) {
                return $this->failure($postId, $found['revision'], 'patch_failed', sprintf(/* translators: 1: index of the patch operation, 2: error message */ __('Operation %1$d: %2$s', 'nucleo'), $e->index, $e->getMessage()));
            }

            return $this->process($postId, $doc, $o, $found['revision']);
        }));
    }

    /**
     * Esegue $fn con il lock del documento: la revisione letta dentro $fn resta quella su cui si scrive.
     * Un dry-run non scrive e non aspetta. Se il lock non arriva, risponde busy senza toccare il post.
     * @param callable(): SaveResult $fn
     */
    private function locked(int $postId, bool $dryRun, callable $fn): SaveResult
    {
        if ($postId <= 0 || $dryRun) {
            return $fn();
        }
        if (!DocumentLock::acquire($postId)) {
            return $this->failure($postId, 0, 'busy', __('Another save of this document is in progress: try again in a moment.', 'nucleo'));
        }
        try {
            return $fn();
        } finally {
            DocumentLock::release($postId);
        }
    }

    /** @return array{document:array<string,mixed>,revision:int}|null */
    public function get(int $postId): ?array
    {
        $found = $this->repository->find($postId);
        if ($found === null || $found['document'] === null) {
            return null;
        }

        return ['document' => $found['document'], 'revision' => $found['revision']];
    }

    /** Ricompila il documento corrente: niente nuova revisione né voce di storico. */
    public function recompile(int $postId): SaveResult
    {
        return $this->locked($postId, false, fn (): SaveResult => self::scoped(function () use ($postId): SaveResult {
            $found = $this->repository->find($postId);
            if ($found === null || $found['document'] === null) {
                return $this->failure($postId, $found['revision'] ?? 0, 'not_found', __('Document does not exist.', 'nucleo'));
            }

            // Il documento salvato conserva i ref: si espandono di nuovo per usare le sezioni correnti.
            $expansion = $this->prepare($found['document'], $postId);
            $doc = $expansion->doc;
            // Una sezione cambiata può rendere il documento non valido: resta l'uscita precedente e l'errore si registra.
            $errors = $this->check($expansion);
            if ($errors !== []) {
                $list = array_values(array_map(static fn (ValidationError $e): array => $e->toArray(), $errors));
                update_post_meta($postId, self::STALE_META, $list);

                return $this->failure($postId, $found['revision'], 'invalid_document', __('Invalid document after a section change: the previous version stays online.', 'nucleo'), $list);
            }
            // Una sezione cambiata può introdurre problemi bloccanti: su un post pubblicato resta la versione precedente.
            $compiled = ContentLocale::run(ContentLocale::forPost($postId), fn () => $this->compiler->compile($doc, $this->bindingsFor($postId, $doc)));
            $report = $this->qualityOf($postId, $doc, $expansion, $this->storedAcks($postId, $doc), strlen($compiled->css));
            if ($report->blocking() && self::isPublished($postId)) {
                $quality = $report->toArray()['quality'];
                update_post_meta($postId, self::QUALITY_STALE_META, $quality['issues']);

                return $this->failure($postId, $found['revision'], 'quality_blocking', __('Blocking quality issues after a section change: the previous version stays online.', 'nucleo'), [], $quality);
            }
            // Prima il CSS: se non si scrive, il post resta con HTML e CSS precedenti.
            $css = $this->writeCss($postId, $compiled->css);
            $id = $this->savePost($postId, new SaveOptions(), $compiled->html);
            if ($id === 0) {
                return $this->failure($postId, $found['revision'], 'write_failed', __('Saving the post failed.', 'nucleo'));
            }

            // update_post_meta() toglie le barre rovesciate: i dati del runtime passano con wp_slash().
            update_post_meta($id, '_nucleo_rt', wp_slash($this->runtime($compiled, $css, $found['document'])));
            $this->syncNoindex($id, $found['document']);
            $this->storeTargets($id, $expansion->targets);
            $this->afterWrite($id, $this->depsFor($compiled, array_merge($this->refs($found['document']['root'] ?? null), $expansion->deps)));

            return new SaveResult(true, $id, $found['revision']);
        }));
    }

    /**
     * @param array<string,mixed> $doc
     */
    public function bindingsFor(int $postId, array $doc): WpBindingSource
    {
        return new WpBindingSource($postId, is_string($doc['kind'] ?? null) ? $doc['kind'] : '');
    }

    /** @param callable(): mixed $fn */
    private static function scoped(callable $fn): mixed
    {
        $previous = self::$writing;
        self::$writing = true;
        try {
            return $fn();
        } finally {
            self::$writing = $previous;
        }
    }

    private function conflicts(int $current, SaveOptions $o): bool
    {
        return $o->baseRevision !== null && $o->baseRevision !== $current;
    }

    private function conflict(int $postId, int $rev): SaveResult
    {
        return $this->failure($postId, $rev, 'conflict', __('The revision has changed: reload the document and try again.', 'nucleo'));
    }

    /** @param array<string,mixed> $doc */
    private function process(int $postId, array $doc, SaveOptions $o, int $rev): SaveResult
    {
        // nc/menu accetta lo slug del menu ("principale" o "menu:principale"): si salva l'ID, come nell'import dei file.
        try {
            $doc = SymbolicRefs::menus($doc, static function (string $symbol): ?int {
                $menu = wp_get_nav_menu_object(substr($symbol, 5));

                return $menu instanceof \WP_Term ? (int) $menu->term_id : null;
            });
        } catch (\InvalidArgumentException $e) {
            return $this->failure($postId, $rev, 'invalid_document', $e->getMessage());
        }

        // Condizioni dei layout: si bloccano solo quelle nuove o cambiate. Condizioni già salvate in un formato che il
        // runtime non applica (tipi di versioni vecchie) non impediscono di correggere o ricompilare il resto del layout:
        // site-describe le elenca in layoutConditions.
        $doc = LayoutConditions::normalize($doc);
        $conditionErrors = $this->newConditionErrors($postId, $doc);

        // Si salva il documento grezzo con i ref; la normalizzazione espande le sezioni solo per validare e compilare.
        $raw = $doc;
        $refs = $this->refs($raw['root'] ?? null);
        $slug = $this->expectedSlug($postId, $o);
        $this->inDryRun = $o->dryRun;
        try {
            $expansion = $this->prepare($doc, $postId, $slug);
        } finally {
            $this->inDryRun = false;
        }
        $doc = $expansion->doc;

        $errors = array_merge($conditionErrors, $this->check($expansion));
        if ($errors !== []) {
            return $this->failure(
                $postId,
                $rev,
                'invalid_document',
                __('Invalid document.', 'nucleo') . self::styleSummary($errors),
                array_values(array_map(static fn (ValidationError $e): array => $e->toArray(), $errors)),
            );
        }

        // Si compila prima della qualità: il peso CSS valutato è quello del documento che si salva, non il file precedente.
        $compiled = ContentLocale::run(ContentLocale::forPost($postId), fn () => $this->compiler->compile($doc, $this->bindingsFor($postId, $doc)));
        $acks = self::mergeAcks($this->storedAcks($postId, $doc), $o->acknowledge);
        $report = $this->qualityOf($postId, $doc, $expansion, $acks, strlen($compiled->css), $o->postType);
        $quality = $report->toArray()['quality'];
        // Conta lo stato dopo il salvataggio: pubblicato, programmato o privato va online e passa dal controllo bloccante;
        // una pagina online riportata in bozza si salva anche con problemi da correggere.
        if ($report->blocking() && self::isPublicStatus(self::finalStatus($postId, $o))) {
            return $this->failure($postId, $rev, 'quality_blocking', __('Blocking quality issues: fix them or acknowledge them.', 'nucleo'), [], $quality);
        }
        if ($o->dryRun) {
            if ($slug !== null && $slug !== '') {
                $this->plan($slug, $raw, $postId);
            }

            return new SaveResult(true, $postId, $rev, [], $quality);
        }

        // Il grezzo si salva migrato alle versioni correnti dei blocchi, con le versioni scritte nel documento.
        $raw = self::stampBlocks($raw, Services::registry());

        $result = $this->write($postId, $raw, $o, $compiled, $quality, $rev, array_merge($refs, $expansion->deps));
        if ($result->ok) {
            $this->storeTargets($result->postId, $expansion->targets);
            $kept = $this->storeAcks($result->postId, $acks, $report, $doc);
            $this->auditAcks($result->postId, $o->acknowledge, $kept);
            $this->storeQuality($result->postId, $report, $quality);
        }

        return $result;
    }

    /**
     * Errori delle condizioni del documento, se diverse da quelle già salvate sul post. Uguali: nessun errore.
     * @param array<string,mixed> $doc
     * @return list<ValidationError>
     */
    private function newConditionErrors(int $postId, array $doc): array
    {
        $errors = LayoutConditions::errorsOf($doc);
        if ($errors === [] || $postId === 0) {
            return $errors;
        }
        $stored = $this->repository->find($postId)['document'] ?? null;
        $before = is_array($stored) ? (LayoutConditions::normalize($stored)['meta']['conditions'] ?? null) : null;
        if ($before !== null && $before == ($doc['meta']['conditions'] ?? null)) {
            return [];
        }
        // Una condizione language già salvata resta ammessa anche senza rilevamento della lingua: si rifiutano solo le nuove.
        $kept = is_array($stored) ? LayoutConditions::languageConditions(LayoutConditions::normalize($stored)) : [];

        return array_values(array_filter(
            $errors,
            static fn (ValidationError $e): bool => !LayoutConditions::isLanguageError($e) || !in_array($e->got, $kept, false),
        ));
    }

    /**
     * Report di qualità della pagina composta, con i nodi delle sezioni riportati al nodo nc/section-ref.
     * @param array<string,mixed> $doc
     * @param list<array{rule:string,node:string,reason:string}> $acks
     */
    private function qualityOf(int $postId, array $doc, Expansion $expansion, array $acks, ?int $cssBytes = null, string $newType = 'page'): QualityReport
    {
        // Un riconoscimento sul nodo nc/section-ref vale per i nodi espansi della sezione.
        foreach ($expansion->origins as $expanded => $origin) {
            foreach ($acks as $ack) {
                if ($ack['node'] === $origin && $expanded !== $origin) {
                    $acks[] = ['node' => (string) $expanded] + $ack;
                }
            }
        }
        // Un contenuto nuovo non ha ancora un post: si compone con i layout che riceverà, come sul sito pubblico.
        $page = $postId <= 0 && !in_array($doc['kind'] ?? null, ['layout', 'section', 'popup'], true)
            ? $this->composer->composeForNewPage('page:' . $postId, $doc, $newType)
            : $this->composer->composeForPost($postId, 'page:' . $postId, $doc, $cssBytes);
        $report = $this->quality->check($page, $acks)->withoutLayoutIssues();

        return self::scopeReport($doc, self::withSections($report, $expansion, 'page:' . $postId));
    }

    /** Stato del post dopo il salvataggio: publish, lo stato richiesto, oppure quello attuale (draft per un post nuovo). */
    public static function finalStatus(int $postId, SaveOptions $o): string
    {
        if ($o->publish) {
            return 'publish';
        }
        if ($o->status !== '') {
            return $o->status;
        }
        $current = $postId > 0 ? get_post_status($postId) : false;

        return is_string($current) && $current !== '' ? $current : 'draft';
    }

    /** Stati in cui il contenuto è visibile, o lo diventa da solo: il controllo bloccante vale per loro. */
    public static function isPublicStatus(string $status): bool
    {
        return in_array($status, ['publish', 'future', 'private'], true);
    }

    private static function isPublished(int $postId): bool
    {
        // Programmato e privato contano come online: il controllo bloccante vale anche per loro.
        return $postId > 0 && in_array(get_post_status($postId), ['publish', 'future', 'private'], true);
    }

    /**
     * Riconoscimenti salvati ancora validi: decade quello il cui nodo è cambiato dopo il consenso (impronta diversa).
     * Le voci salvate prima dell'impronta restano valide.
     * @param array<string,mixed> $doc documento espanso che si sta salvando o ricompilando
     * @return list<array{rule:string,node:string,reason:string,hash?:string}>
     */
    private function storedAcks(int $postId, array $doc): array
    {
        $stored = $postId > 0 ? get_post_meta($postId, self::ACK_META, true) : [];
        $acks = self::mergeAcks([], is_array($stored) ? $stored : []);

        return array_values(array_filter(
            $acks,
            static fn (array $a): bool => !isset($a['hash']) || $a['hash'] === self::nodeHash($doc, $a['node']),
        ));
    }

    /**
     * Impronta di un nodo senza i figli: cambia con tipo, contenuto, impostazioni e stile del nodo.
     * @param array<string,mixed> $doc
     */
    private static function nodeHash(array $doc, string $id): ?string
    {
        $node = self::findNode($doc['root'] ?? null, $id);
        if ($node === null) {
            return null;
        }
        unset($node['children']);

        return sha1((string) wp_json_encode($node));
    }

    /** @return array<string,mixed>|null */
    private static function findNode(mixed $node, string $id): ?array
    {
        if (!is_array($node)) {
            return null;
        }
        if (($node['id'] ?? null) === $id) {
            return $node;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            $found = self::findNode($child, $id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Unisce i riconoscimenti: per la stessa coppia regola+nodo vince il più recente. Scarta le voci malformate.
     * @param array<mixed> $old
     * @param array<mixed> $new
     * @return list<array{rule:string,node:string,reason:string,hash?:string}>
     */
    private static function mergeAcks(array $old, array $new): array
    {
        $out = [];
        foreach ([...$old, ...$new] as $ack) {
            if (is_array($ack) && is_string($ack['rule'] ?? null) && is_string($ack['node'] ?? null) && is_string($ack['reason'] ?? null)) {
                $entry = ['rule' => $ack['rule'], 'node' => $ack['node'], 'reason' => $ack['reason']];
                if (is_string($ack['hash'] ?? null)) {
                    $entry['hash'] = $ack['hash'];
                }
                $out[$ack['rule'] . "\0" . $ack['node']] = $entry;
            }
        }

        return array_values($out);
    }

    /**
     * Conserva solo i riconoscimenti che coprono ancora un problema: un nodo rimosso o corretto li fa decadere.
     * Ogni voce porta l'impronta del nodo al momento del consenso: se il nodo cambia, il consenso decade.
     * @param list<array{rule:string,node:string,reason:string,hash?:string}> $acks
     * @param array<string,mixed> $doc documento espanso salvato
     * @return list<array{rule:string,node:string,reason:string,hash?:string}> consensi salvati
     */
    private function storeAcks(int $postId, array $acks, QualityReport $report, array $doc): array
    {
        $used = [];
        foreach ($report->acknowledged as $issue) {
            foreach ($issue->nodes as $node) {
                $used[$issue->rule . "\0" . $node['id']] = true;
            }
        }
        $keep = [];
        foreach ($acks as $a) {
            if (isset($used[$a['rule'] . "\0" . $a['node']])) {
                $hash = self::nodeHash($doc, $a['node']);
                $keep[] = ['rule' => $a['rule'], 'node' => $a['node'], 'reason' => $a['reason']] + ($hash !== null ? ['hash' => $hash] : []);
            }
        }
        if ($keep === []) {
            delete_post_meta($postId, self::ACK_META);
        } else {
            update_post_meta($postId, self::ACK_META, $keep);
        }

        return $keep;
    }

    /**
     * Ogni consenso ricevuto che il salvataggio ha accettato va nel registro di audit (chi lo dà è l'utente corrente).
     * @param list<array{rule:string,node:string,reason:string}> $given consensi ricevuti con il salvataggio
     * @param list<array{rule:string,node:string,reason:string,hash?:string}> $kept consensi salvati
     */
    private function auditAcks(int $postId, array $given, array $kept): void
    {
        $saved = [];
        foreach ($kept as $ack) {
            $saved[$ack['rule'] . "\0" . $ack['node']] = true;
        }
        foreach (self::mergeAcks([], $given) as $ack) {
            if (isset($saved[$ack['rule'] . "\0" . $ack['node']])) {
                AuditLog::record('quality.ack', $postId, true, $ack['rule'] . ' ' . $ack['node'] . ': ' . $ack['reason']);
            }
        }
    }

    /**
     * Esito del controllo di qualità sul post salvato: errori, avvisi, punteggi (seo, a11y, perf) e istante del controllo.
     * @param array<string,mixed> $quality
     */
    private function storeQuality(int $postId, QualityReport $report, array $quality): void
    {
        update_post_meta($postId, self::QUALITY_META, [
            'errors' => count(array_filter($report->issues, static fn (Issue $i): bool => $i->severity === Severity::Error)),
            'warnings' => count(array_filter($report->issues, static fn (Issue $i): bool => $i->severity === Severity::Warning)),
            'score' => $quality['score'],
            'checked' => gmdate('c'),
        ]);
    }

    /**
     * Migra i nodi del documento grezzo alle versioni correnti dei loro blocchi (solo forme dei nodi, niente espansione)
     * e scrive nel documento la mappa blocks calcolata dopo la migrazione. Senza tipi noti la chiave blocks non resta.
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    public static function stampBlocks(array $doc, Registry $registry): array
    {
        $doc = (new Migrator($registry))->migrate($doc);
        $blocks = self::blockVersions($doc, $registry);
        if ($blocks === []) {
            unset($doc['blocks']);
        } else {
            $doc['blocks'] = $blocks;
        }

        return $doc;
    }

    /**
     * Versione corrente di ogni tipo di blocco presente nel documento, nodi e figli compresi. Tipi che il registro
     * non conosce restano fuori. Pura: legge solo il documento e il registro.
     * @param array<string,mixed> $doc
     * @return array<string,int> tipo di blocco → versione del manifest
     */
    public static function blockVersions(array $doc, Registry $registry): array
    {
        $versions = [];
        self::collectVersions($doc['root'] ?? null, $registry, $versions);
        ksort($versions);

        return $versions;
    }

    /** @param array<string,int> $versions */
    private static function collectVersions(mixed $node, Registry $registry, array &$versions): void
    {
        if (!is_array($node)) {
            return;
        }
        $manifest = is_string($node['type'] ?? null) ? $registry->get($node['type']) : null;
        if ($manifest !== null) {
            $versions[$manifest->name] = $manifest->version;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            self::collectVersions($child, $registry, $versions);
        }
    }

    /** @param array<string,string> $targets */
    private function storeTargets(int $postId, array $targets): void
    {
        if ($targets === []) {
            delete_post_meta($postId, self::LINKS_META);
        } else {
            update_post_meta($postId, self::LINKS_META, $targets);
        }
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $quality
     * @param list<string> $refs
     */
    private function write(int $postId, array $doc, SaveOptions $o, CompileResult $compiled, array $quality, int $rev, array $refs): SaveResult
    {
        // Post esistente: prima il CSS, così un errore di scrittura lascia HTML e CSS precedenti. Un post nuovo
        // riceve l'id da savePost e non ha versioni precedenti da proteggere.
        $css = $postId > 0 ? $this->writeCss($postId, $compiled->css) : null;
        $id = $this->savePost($postId, $o, $compiled->html);
        if ($id === 0) {
            return $this->failure($postId, $rev, 'write_failed', __('Saving the post failed.', 'nucleo'), [], $quality);
        }
        $css ??= $this->writeCss($id, $compiled->css);
        $newRev = $rev + 1;
        $this->repository->store($id, $doc, $newRev, $this->runtime($compiled, $css, $doc));
        $this->syncNoindex($id, $doc);
        $this->afterWrite($id, $this->depsFor($compiled, $refs));

        return new SaveResult(true, $id, $newRev, [], $quality);
    }

    /**
     * Salva le dipendenze, notifica i listener e svuota la cache della pagina (o di tutto, per un layout).
     * @param list<string> $deps
     */
    private function afterWrite(int $id, array $deps): void
    {
        delete_post_meta($id, self::STALE_META);
        delete_post_meta($id, self::QUALITY_STALE_META);
        delete_post_meta($id, self::DEP_META);
        foreach ($deps as $dep) {
            add_post_meta($id, self::DEP_META, $dep);
        }

        do_action('nucleo/document_saved', $id);

        if (get_post_type($id) === 'nucleo_layout') {
            CachePurger::purgeAll();
        } else {
            CachePurger::purgePost($id);
        }
    }

    /**
     * @param list<string> $refs
     * @return list<string>
     */
    private function depsFor(CompileResult $compiled, array $refs): array
    {
        return array_values(array_unique(array_merge($compiled->deps, $refs)));
    }

    /**
     * Raccoglie i ref "section:N" risolti nell'albero del documento.
     * @return list<string>
     */
    private function refs(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }

        $refs = [];
        if (is_string($node['ref'] ?? null) && $this->resolveRef($node['ref']) !== null) {
            $refs[] = $node['ref'];
        }
        foreach ($node['children'] ?? [] as $child) {
            $refs = array_merge($refs, $this->refs($child));
        }

        return $refs;
    }

    /**
     * Titolo del post: kses è spento per l'HTML dei renderer, quindi il titolo passa qui dal filtro che WordPress
     * applica di solito (title_save_pre), salvo per chi ha unfiltered_html.
     */
    private static function title(string $title): string
    {
        return current_user_can('unfiltered_html') ? $title : wp_kses_data($title);
    }

    /**
     * Scrive il post con kses disattivato: l'HTML arriva dai renderer, già sanificato. wp_insert_post() e
     * wp_update_post() tolgono le barre rovesciate: HTML e titolo passano con wp_slash(), altrimenti un JSON in un
     * attributo («\u00c5land») o un testo con «\» arriva rotto.
     */
    private function savePost(int $postId, SaveOptions $o, string $html): int
    {
        $html = wp_slash($html);
        kses_remove_filters();
        try {
            if ($postId === 0) {
                $data = [
                    'post_title' => wp_slash(self::title($o->title)),
                    'post_type' => $o->postType,
                    'post_status' => $o->publish ? 'publish' : ($o->status !== '' ? $o->status : 'draft'),
                    'post_content' => $html,
                ];
                // Una bozza non riceve slug da WordPress: lo slug si assegna qui, unico come alla pubblicazione.
                // nc/section-ref cerca la sezione per slug anche in bozza; doc-create restituisce lo slug vero.
                $slug = $this->newSlug($o);
                if ($slug !== '') {
                    $data['post_name'] = $slug;
                }
                if ($o->parent !== null) {
                    $data['post_parent'] = $o->parent;
                }
                $result = wp_insert_post($data, true);
            } else {
                $data = ['ID' => $postId, 'post_content' => $html];
                if ($o->title !== '') {
                    $data['post_title'] = wp_slash(self::title($o->title));
                }
                if ($o->publish) {
                    $data['post_status'] = 'publish';
                } elseif ($o->status !== '') {
                    $data['post_status'] = $o->status;
                }
                if ($o->parent !== null) {
                    $data['post_parent'] = $o->parent;
                }
                $result = wp_update_post($data, true);
            }
        } finally {
            kses_init_filters();
        }

        return is_wp_error($result) ? 0 : (int) $result;
    }

    /** @return array{file:string,url:string} */
    private function writeCss(int $id, string $css): array
    {
        $uploads = wp_upload_dir();
        if ($uploads['error'] !== false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new RuntimeException(sprintf(/* translators: %s: upload error message. */ __('Uploads folder unavailable: %s', 'nucleo'), $uploads['error']));
        }

        $dir = $uploads['basedir'] . '/nucleo/css';
        wp_mkdir_p($dir);
        $name = CssFiles::write($dir, $id, $css);

        return ['file' => $dir . '/' . $name, 'url' => $uploads['baseurl'] . '/nucleo/css/' . $name];
    }

    /**
     * @param array{file:string,url:string} $css
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private function runtime(CompileResult $compiled, array $css, array $document): array
    {
        // Pagina senza stili propri: il file vuoto resta su disco ma non si accoda.
        $styles = filesize($css['file']) > 0 ? [BlockBundle::url(), $css['url']] : [BlockBundle::url()];

        $eager = [];
        $lazy = [];
        foreach ($compiled->assets['scripts'] as $script) {
            $url = $this->assetUrl($script['src']);
            if ($script['strategy'] === 'eager') {
                $eager[] = $url;
            } else {
                $lazy[] = ['type' => $script['type'], 'src' => $url, 'strategy' => $script['strategy']];
            }
        }

        $runtime = [
            'cssFile' => $css['file'],
            'cssUrl' => $css['url'],
            'styles' => $styles,
            'eager' => $eager,
            'lazy' => $lazy,
            'slots' => $compiled->slots,
            'lcp' => $compiled->lcpNodeId,
            'breadcrumbBlock' => in_array('nc/breadcrumbs', $compiled->blockTypes, true),
        ];

        // meta.seo solo se il documento ne ha: senza, il frontend non carica il modulo SEO.
        $seo = $document['meta']['seo'] ?? null;
        if (is_array($seo) && $seo !== []) {
            $runtime['seo'] = $seo;
        }

        return $runtime;
    }

    /**
     * Meta noindex del post, letto dal sitemap: presente solo se il documento ha meta.seo.noindex vero.
     * @param array<string,mixed> $document
     */
    private function syncNoindex(int $id, array $document): void
    {
        if (($document['meta']['seo']['noindex'] ?? null) === true) {
            update_post_meta($id, StructuredData::NOINDEX_META, '1');
        } else {
            delete_post_meta($id, StructuredData::NOINDEX_META);
        }
    }

    /** Converte un percorso assoluto dentro NUCLEO_DIR in URL del plugin. */
    private function assetUrl(string $absolutePath): string
    {
        $base = rtrim(NUCLEO_DIR, '/') . '/';
        $relative = ltrim(substr($absolutePath, strlen($base)), '/');

        return plugins_url($relative, $base . 'index.php');
    }

    /**
     * Normalizza il documento ed espande i blocchi nc/section-ref; il risultato va a validazione, qualità e compilatore.
     * Il secondo passaggio del normalizzatore completa i default dei nodi arrivati dalle sezioni.
     * @param array<string,mixed> $doc
     */
    public function prepare(array $doc, int $postId = 0, ?string $self = null): Expansion
    {
        $doc = $this->normalizer->normalize($doc, fn (string $ref): ?array => $this->resolveRef($ref));
        if ($self === null && $postId > 0 && get_post_type($postId) === PostTypes::SECTION) {
            $self = (string) get_post_field('post_name', $postId);
        }
        $expansion = (new SectionExpander(fn (string $slug): ?array => $this->findSection($slug)))->expand($doc, $self === '' ? null : $self);

        // I link "page:<slug>" e simili diventano URL dopo l'espansione: valgono anche quelli arrivati dai parametri.
        // I bersagli della compilazione precedente si cercano per id: il link segue il contenuto rinominato.
        $known = $postId > 0 ? get_post_meta($postId, self::LINKS_META, true) : [];
        $known = is_array($known) ? $known : [];
        $targets = [];
        $links = LinkRefs::resolve(
            $this->normalizer->normalize($expansion->doc),
            static function (array $ref) use ($known, &$targets): ?array {
                $key = LinkTargets::key($ref);
                $found = LinkTargets::lookup($ref, isset($known[$key]) && is_string($known[$key]) ? $known[$key] : null);
                if ($found !== null && $found['dep'] !== null) {
                    $targets[$key] = $found['dep'];
                }

                return $found;
            },
        );

        return new Expansion(
            $links['doc'],
            $expansion->errors,
            $expansion->missing,
            array_values(array_unique(array_merge($expansion->deps, $links['deps']))),
            $expansion->origins,
            $expansion->bound,
            $links['missing'],
            $links['paths'],
            $targets,
        );
    }

    /**
     * Errori dell'espansione e dello schema; quelli sui nodi delle sezioni puntano al nc/section-ref del documento.
     * @return list<ValidationError>
     */
    public function check(Expansion $expansion): array
    {
        $schema = ErrorMapper::remap($expansion->doc, $expansion, $this->validator->validate($expansion->doc));

        return array_values(array_merge($expansion->errors, $schema));
    }

    /** Slug della sezione che si sta salvando, anche prima che il post esista: serve a vedere i cicli. */
    private function expectedSlug(int $postId, SaveOptions $o): ?string
    {
        if ($postId > 0) {
            return get_post_type($postId) === PostTypes::SECTION ? (string) get_post_field('post_name', $postId) : null;
        }

        return $o->postType === PostTypes::SECTION ? $this->newSlug($o) : null;
    }

    /** Slug di un contenuto nuovo: quello richiesto o quello del titolo, reso unico come alla pubblicazione. */
    private function newSlug(SaveOptions $o): string
    {
        $base = sanitize_title($o->slug !== '' ? $o->slug : $o->title);

        return $base === '' ? '' : wp_unique_post_slug($base, 0, 'publish', $o->postType, $o->parent ?? 0);
    }

    /**
     * Sezione per slug, con documento già normalizzato. Bozze e sezioni private solo per chi gestisce il tema,
     * o senza utente (WP-Cron, WP-CLI): un autore non pubblica il contenuto di una sezione che non può vedere.
     * @return array{id:int,doc:array<string,mixed>}|null
     */
    private function findSection(string $slug): ?array
    {
        if ($this->inDryRun && isset($this->planned[$slug])) {
            return ['id' => $this->planned[$slug]['id'], 'doc' => $this->normalizer->normalize($this->planned[$slug]['doc'])];
        }
        $all = !is_user_logged_in() || current_user_can('edit_theme_options');
        $ids = get_posts([
            'name' => $slug,
            'post_type' => PostTypes::SECTION,
            'post_status' => $all ? ['publish', 'draft', 'pending', 'private', 'future'] : ['publish'],
            'numberposts' => 1,
            'fields' => 'ids',
        ]);
        $id = (int) ($ids[0] ?? 0);
        $found = $id > 0 ? $this->repository->find($id) : null;
        if ($found === null || $found['document'] === null) {
            return null;
        }

        return ['id' => $id, 'doc' => $this->normalizer->normalize($found['document'])];
    }

    /**
     * Aggiunge al report un avviso per ogni sezione mancante e riporta i nodi espansi al nodo nc/section-ref del documento:
     * l'agente corregge il documento che ha scritto, non la copia espansa.
     */
    public static function withSections(QualityReport $report, Expansion $expansion, string $doc): QualityReport
    {
        $map = static function (Issue $issue) use ($expansion): Issue {
            $mapped = false;
            $nodes = [];
            foreach ($issue->nodes as $node) {
                $origin = $expansion->origins[$node['id']] ?? null;
                if ($origin !== null && $origin !== $node['id']) {
                    $mapped = true;
                }
                $nodes[] = ['doc' => $node['doc'], 'id' => $origin ?? $node['id']];
            }
            if (!$mapped) {
                return $issue;
            }
            // Le correzioni puntano ai nodi interni della sezione: nel documento non esistono.
            return new Issue($issue->rule, $issue->severity, $issue->message . ' ' . __('(inside a reusable section)', 'nucleo'), $nodes, [], $issue->publishBlocking);
        };

        $issues = array_map($map, $report->issues);
        foreach ($expansion->links as $link) {
            $issues[] = $map(new Issue(
                'content.link.missing',
                Severity::Warning,
                sprintf(/* translators: %s: link reference such as page:contact. */ __('Link "%s" points to content that does not exist or is not published: the link renders as # with aria-disabled.', 'nucleo'), $link['ref']),
                [['doc' => $doc, 'id' => $link['id']]],
            ));
        }
        foreach ($expansion->paths as $path) {
            $target = LinkTargets::refFor($path['href']);
            if ($target !== null) {
                $issues[] = $map(new Issue(
                    'content.link.path',
                    Severity::Info,
                    sprintf(/* translators: 1: link path, 2: suggested reference. */ __('Internal link written as a path (%1$s): use "%2$s" so the link follows slug changes.', 'nucleo'), $path['href'], $target),
                    [['doc' => $doc, 'id' => $path['id']]],
                ));
            }
        }
        foreach ($expansion->missing as $missing) {
            $issues[] = new Issue(
                'content.section.missing',
                Severity::Warning,
                sprintf(/* translators: %s: section slug. */ __('Reusable section "%s" not found or in the trash: the block renders nothing.', 'nucleo'), $missing['section']),
                [['doc' => $doc, 'id' => $missing['id']]],
            );
        }

        return new QualityReport($issues, array_map($map, $report->acknowledged));
    }

    /**
     * Risolve "section:N" con il documento del post N.
     * @return array<string,mixed>|null
     */
    private function resolveRef(string $ref): ?array
    {
        if (preg_match('/^section:(\d+)$/', $ref, $m) !== 1) {
            return null;
        }
        $found = $this->repository->find((int) $m[1]);

        return $found === null ? null : $found['document'];
    }

    /**
     * Tutte le chiavi di stile rifiutate dal loro blocco, in una frase: chi legge solo il messaggio
     * (push, che mostra il primo errore del file) le corregge insieme, ognuna con il posto dove va.
     * @param list<ValidationError> $errors
     */
    private static function styleSummary(array $errors): string
    {
        $items = [];
        foreach ($errors as $e) {
            if ($e->code === DocumentValidator::STYLE_GROUP_CODE) {
                $items[] = $e->path . ': ' . (string) $e->fix;
            }
        }
        if ($items === []) {
            return '';
        }

        return ' ' . sprintf(
            /* translators: 1: number of style keys, 2: list of "path: where the key goes". */
            _n('%1$d style key not accepted by its block: %2$s.', '%1$d style keys not accepted by their blocks: %2$s.', count($items), 'nucleo'),
            count($items),
            implode('; ', $items),
        );
    }

    /**
     * @param list<array<string,mixed>> $errors
     * @param array<string,mixed>|null $quality
     */
    private function failure(int $postId, int $rev, string $code, string $message, array $errors = [], ?array $quality = null): SaveResult
    {
        return new SaveResult(
            false,
            $postId,
            $rev,
            $errors,
            $quality ?? [],
            ['code' => $code, 'message' => $message],
        );
    }
}
