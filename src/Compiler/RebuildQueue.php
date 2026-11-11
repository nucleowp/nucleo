<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

defined('ABSPATH') || exit;

use Nucleo\Services;

/**
 * Coda di ricompilazione in background: id dei documenti da ricompilare, elaborati a lotti via WP-Cron.
 * La coda è una mappa id => generazione: ogni accodamento incrementa il contatore globale, così un worker
 * toglie un id solo se nessun accodamento più recente l'ha toccato mentre lo ricompilava.
 */
final class RebuildQueue
{
    public const HOOK = 'nucleo_rebuild';
    private const OPTION = 'nucleo_rebuild_queue';
    private const GENERATION_OPTION = 'nucleo_rebuild_gen';
    private const FAILED_OPTION = 'nucleo_rebuild_failed';
    private const MUTEX = 'nucleo_rebuild_mutex';
    private const MUTEX_SECONDS = 5;
    private const WORKER_LOCK = 'nucleo_rebuild_worker';
    private const BUDGET_SECONDS = 20;
    private const MAX_ATTEMPTS = 3;
    private const BACKOFF_SECONDS = 30;

    /** Vero mentre run() lavora: una ricompilazione che salva una sezione accoda soltanto, il ciclo esterno la riprende. */
    private static bool $running = false;

    /** @param list<int> $ids */
    public static function enqueue(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }
        // Scrive anche senza mutex: un accodamento non deve perdere id.
        self::withMutex(static function () use ($ids): void {
            $generation = (int) get_option(self::GENERATION_OPTION, 0) + 1;
            update_option(self::GENERATION_OPTION, $generation, false);
            $queue = self::queue();
            foreach ($ids as $id) {
                $queue[$id] = $generation;
            }
            update_option(self::OPTION, $queue, false);
            self::clearFailed($ids);
        }, true);
        self::schedule(time());
    }

    /**
     * Toglie dalla coda i documenti appena scritti: ricompilarli rileggerebbe lo stesso documento.
     * @param list<int> $ids
     */
    public static function forget(array $ids): void
    {
        $ids = array_map('intval', $ids);
        self::withMutex(static function () use ($ids): void {
            $queue = self::queue();
            $left = array_diff_key($queue, array_flip($ids));
            if (count($left) !== count($queue)) {
                update_option(self::OPTION, $left, false);
            }
        }, false);
    }

    public static function enqueueAll(): void
    {
        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_nucleo_doc'"
            . ' ORDER BY p.ID ASC'
        );
        self::enqueue(array_values(array_map('intval', $ids)));
    }

    public static function pending(): int
    {
        return count(self::queue());
    }

    /**
     * Ricompilazioni fallite, una voce per documento: tentativi, codice, messaggio e istante dell'ultimo errore.
     * @return list<array{id:int,attempts:int,code:string,message:string}>
     */
    public static function failed(): array
    {
        $failed = [];
        foreach (self::failedRecords() as $id => $record) {
            $failed[] = [
                'id' => (int) $id,
                'attempts' => (int) ($record['attempts'] ?? 0),
                'code' => (string) ($record['code'] ?? ''),
                'message' => (string) ($record['message'] ?? ''),
            ];
        }

        return $failed;
    }

    /** Ricompila fino a $max documenti o per BUDGET_SECONDS; restituisce i rimanenti. */
    public static function run(int $max = 20): int
    {
        if (self::$running || !self::acquireWorkerLock()) {
            return self::pending();
        }
        self::$running = true;
        try {
            return self::drain($max);
        } finally {
            self::$running = false;
            self::releaseWorkerLock();
        }
    }

    /**
     * Prossimo id da ricompilare: il primo in coda la cui attesa è scaduta (o che non ha fallimenti);
     * null se restano solo id in attesa del prossimo tentativo.
     * @param array<int,int> $queue id => generazione
     * @param array<int,array<string,mixed>> $records
     */
    public static function pick(array $queue, array $records, int $now): ?int
    {
        foreach (array_keys($queue) as $id) {
            if ((int) ($records[$id]['next'] ?? 0) <= $now) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Esito di un tentativo sul documento $id, preso con la generazione $gen. Se la generazione non è più quella
     * in coda (accodamento più recente) o l'id è stato tolto, coda e registro restano com'erano.
     * Un fallimento riaccoda l'id in fondo finché non raggiunge MAX_ATTEMPTS; la prossima attesa cresce di 30 s per tentativo.
     * @param array<int,int> $queue id => generazione
     * @param array<int,array<string,mixed>> $records
     * @param array{code:string,message:string}|null $failure
     * @return array{0:array<int,int>,1:array<int,array<string,mixed>>} [coda, registro]
     */
    public static function settled(array $queue, array $records, int $id, int $gen, ?array $failure, int $now): array
    {
        if (($queue[$id] ?? null) !== $gen) {
            return [$queue, $records];
        }
        unset($queue[$id]);
        if ($failure === null) {
            unset($records[$id]);

            return [$queue, $records];
        }
        $attempts = (int) ($records[$id]['attempts'] ?? 0) + 1;
        $records[$id] = [
            'attempts' => $attempts,
            'code' => $failure['code'],
            'message' => $failure['message'],
            'at' => gmdate('c', $now),
            'next' => $now + self::BACKOFF_SECONDS * $attempts,
        ];
        if ($attempts < self::MAX_ATTEMPTS) {
            $queue[$id] = $gen;
        }

        return [$queue, $records];
    }

    private static function drain(int $max): int
    {
        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $done = 0;
        while ($done < $max && microtime(true) < $deadline) {
            $queue = self::queue();
            $id = self::pick($queue, self::failedRecords(), time());
            if ($id === null) {
                break;
            }
            // L'id resta in coda fino all'esito: se il processo si interrompe, non sparisce.
            self::settle($id, $queue[$id], self::attempt($id));
            $done++;
        }

        $left = self::pending();
        $queue = self::queue();
        if ($queue !== []) {
            $records = self::failedRecords();
            $now = time();
            // Con solo id in attesa, un'unica azione all'attesa più breve; se restano id pronti, adesso.
            $at = min(array_map(
                static fn (int $id): int => max($now, (int) ($records[$id]['next'] ?? 0)),
                array_keys($queue)
            ));
            self::schedule($at);
        }

        return $left;
    }

    /** @return array{code:string,message:string}|null null se la ricompilazione riesce */
    private static function attempt(int $id): ?array
    {
        try {
            $result = Services::documents()->recompile($id);
        } catch (\Throwable $e) {
            return ['code' => 'exception', 'message' => $e->getMessage()];
        }
        if ($result->ok) {
            return null;
        }

        return ['code' => (string) ($result->error['code'] ?? 'unknown'), 'message' => (string) ($result->error['message'] ?? '')];
    }

    /**
     * Registra l'esito di un tentativo sotto mutex. Senza mutex non scrive: l'id resta in coda e si ritenta.
     * @param array{code:string,message:string}|null $failure
     */
    private static function settle(int $id, int $gen, ?array $failure): void
    {
        self::withMutex(static function () use ($id, $gen, $failure): void {
            [$queue, $records] = self::settled(self::queue(), self::failedRecords(), $id, $gen, $failure, time());
            update_option(self::FAILED_OPTION, $records, false);
            update_option(self::OPTION, $queue, false);
        }, false);
    }

    /** @param list<int> $ids chiamato sotto mutex */
    private static function clearFailed(array $ids): void
    {
        $records = self::failedRecords();
        $left = array_diff_key($records, array_flip($ids));
        if (count($left) !== count($records)) {
            update_option(self::FAILED_OPTION, $left, false);
        }
    }

    /** @return array<int,array{attempts:int,code:string,message:string,at:string,next:int}> */
    private static function failedRecords(): array
    {
        $raw = get_option(self::FAILED_OPTION, []);

        return is_array($raw) ? $raw : [];
    }

    /**
     * Esegue $fn sotto il lock MySQL della coda. Senza lock $fn gira solo se $anyway è vero.
     * @param callable(): void $fn
     */
    private static function withMutex(callable $fn, bool $anyway): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock di MySQL, non un dato da mettere in cache.
        $got = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::MUTEX, self::MUTEX_SECONDS)) === '1';
        if (!$got && !$anyway) {
            return;
        }
        try {
            $fn();
        } finally {
            if ($got) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock di MySQL.
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::MUTEX));
            }
        }
    }

    /** Lock del worker, senza attesa: un secondo worker sulla stessa coda esce subito. */
    private static function acquireWorkerLock(): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock di MySQL, legato alla connessione.
        return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::WORKER_LOCK)) === '1';
    }

    private static function releaseWorkerLock(): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock di MySQL.
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::WORKER_LOCK));
    }

    public static function register(): void
    {
        add_action(self::HOOK, static function (): void {
            self::run();
        });
    }

    /**
     * id => generazione. Il formato a lista precedente (solo id) vale generazione 0.
     * @return array<int,int>
     */
    private static function queue(): array
    {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) {
            return [];
        }
        // Una lista ha chiavi 0..n-1: un id non è mai 0, quindi nella mappa non succede.
        $legacy = array_keys($raw) === range(0, count($raw) - 1);
        $queue = [];
        foreach ($raw as $key => $value) {
            $id = $legacy ? (int) $value : (int) $key;
            if ($id > 0) {
                $queue[$id] = $legacy ? 0 : (int) $value;
            }
        }

        return $queue;
    }

    /** Pianifica il worker a $at (timestamp): adesso, o all'attesa più breve dei fallimenti. */
    private static function schedule(int $at): void
    {
        // Action Scheduler, se presente: l'azione è in coda anche fuori da WP-Cron, senza duplicati.
        if (function_exists('as_enqueue_async_action')) {
            if (!as_has_scheduled_action(self::HOOK, [], 'nucleo')) {
                if ($at <= time()) {
                    as_enqueue_async_action(self::HOOK, [], 'nucleo');
                } else {
                    as_schedule_single_action($at, self::HOOK, [], 'nucleo');
                }
            }

            return;
        }

        if (wp_next_scheduled(self::HOOK) === false) {
            wp_schedule_single_event($at, self::HOOK);
            if ($at <= time()) {
                spawn_cron();
            }
        }
    }
}
