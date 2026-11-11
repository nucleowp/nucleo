<?php

declare(strict_types=1);

namespace Nucleo\Forms;

defined('ABSPATH') || exit;

/**
 * Email inviate da Nucleo (codici OTP, azioni email dei moduli) ed esito degli invii.
 *
 * Registro di sviluppo: in local, salvo NUCLEO_DEV_MAIL false, le email di Nucleo vanno nell'opzione nucleo_dev_mail
 * (ultime 20, al massimo 24 ore) invece di partire. In development serve NUCLEO_DEV_MAIL true. In staging e production
 * non si accende mai. Le email di WordPress e degli altri plugin non passano dal registro.
 */
final class DevMail
{
    public const OPTION = 'nucleo_dev_mail';
    public const STATUS_OPTION = 'nucleo_mail_status';
    public const PRUNE_HOOK = 'nucleo_dev_mail_prune';
    private const LIMIT = 20;
    private const MAX_BODY = 5000;
    /** Dopo 7 giorni senza altri fallimenti l'avviso sparisce. */
    private const FAILURE_NOTICE_TTL = 7 * DAY_IN_SECONDS;

    /** Esito degli invii e pulizia del registro. Chiamato da Plugin in admin, REST, CLI e cron. */
    public static function register(): void
    {
        add_action('wp_mail_failed', [self::class, 'failed']);
        add_action('wp_mail_succeeded', [self::class, 'succeeded']);
        add_action(self::PRUNE_HOOK, [self::class, 'prune']);
    }

    /**
     * Ambiente e costante che accendono il registro: local salvo costante false; development solo con costante true.
     * $constant è null se NUCLEO_DEV_MAIL non è definita o non è un booleano.
     */
    public static function allowedIn(string $environment, ?bool $constant): bool
    {
        return match ($environment) {
            'local' => $constant !== false,
            'development' => $constant === true,
            default => false,
        };
    }

    public static function active(): bool
    {
        $constant = defined('NUCLEO_DEV_MAIL') && is_bool(constant('NUCLEO_DEV_MAIL')) ? constant('NUCLEO_DEV_MAIL') : null;
        if (!self::allowedIn(wp_get_environment_type(), $constant)) {
            return false;
        }

        return (bool) apply_filters('nucleo/dev_mail', true);
    }

    /**
     * Invio di un'email di Nucleo: con il registro attivo la salva e restituisce true, altrimenti wp_mail.
     *
     * @param string|list<string> $to
     * @param list<string> $headers
     */
    public static function send(string|array $to, string $subject, string $message, array $headers = []): bool
    {
        if (!self::active()) {
            $sent = wp_mail($to, $subject, $message, $headers);
            if (!$sent) {
                self::failed(null);
            }

            return $sent;
        }

        $entries = self::fresh(get_option(self::OPTION, []));
        $entries[] = [
            'time' => time(),
            'to' => self::recipients($to),
            'subject' => $subject,
            'body' => mb_substr($message, 0, self::MAX_BODY),
        ];
        update_option(self::OPTION, array_slice($entries, -self::LIMIT), false);
        if (wp_next_scheduled(self::PRUNE_HOOK) === false) {
            wp_schedule_single_event(time() + DAY_IN_SECONDS, self::PRUNE_HOOK);
        }

        return true;
    }

    /** Hook wp_mail_failed, e invio di Nucleo non partito: data dell'ultimo fallimento. */
    public static function failed(mixed $error): void
    {
        $code = $error instanceof \WP_Error ? (string) $error->get_error_code() : 'wp_mail_failed';
        update_option(self::STATUS_OPTION, ['ok' => false, 'time' => time(), 'code' => $code], false);
    }

    /** Hook wp_mail_succeeded: ultimo esito riuscito. */
    public static function succeeded(): void
    {
        update_option(self::STATUS_OPTION, ['ok' => true, 'time' => time()], false);
    }

    /**
     * Ultime email del registro, dalla meno recente. Vuoto se il registro non è attivo.
     *
     * @return list<array{time:int,to:list<string>,subject:string,body:string}>
     */
    public static function entries(int $limit): array
    {
        if (!self::active()) {
            return [];
        }

        return array_slice(self::fresh(get_option(self::OPTION, [])), -max(1, $limit));
    }

    /** Cron: toglie le voci oltre le 24 ore; cancella l'opzione se resta vuota o se il registro non è più attivo. */
    public static function prune(): void
    {
        $entries = self::active() ? self::fresh(get_option(self::OPTION, [])) : [];
        if ($entries === []) {
            delete_option(self::OPTION);

            return;
        }
        update_option(self::OPTION, $entries, false);
        wp_schedule_single_event(time() + DAY_IN_SECONDS, self::PRUNE_HOOK);
    }

    /** Disattivazione del plugin: niente codici né dati dei moduli restano nel database. */
    public static function deactivate(): void
    {
        delete_option(self::OPTION);
        delete_option(self::STATUS_OPTION);
        wp_clear_scheduled_hook(self::PRUNE_HOOK);
    }

    /** @return list<string> */
    public static function warnings(): array
    {
        $out = [];
        if (self::active()) {
            $out[] = 'Development mail is on (' . wp_get_environment_type() . '): emails sent by Nucleo (one-time codes, form emails) are written to the mail log instead of being sent. Read them with mail-log. Other WordPress emails are sent as usual.';
        }
        $status = get_option(self::STATUS_OPTION, []);
        $time = is_array($status) ? (int) ($status['time'] ?? 0) : 0;
        if (is_array($status) && ($status['ok'] ?? true) === false && $time > time() - self::FAILURE_NOTICE_TTL) {
            $out[] = sprintf(
                'An email failed on %s (UTC) and no email was sent after it: the site may not send email, so one-time codes and form emails do not arrive. Configure an SMTP plugin. Only emails sent from admin, REST, CLI or cron are tracked.',
                gmdate('Y-m-d H:i', $time),
            );
        }

        return $out;
    }

    /**
     * Voci non più vecchie di 24 ore.
     *
     * @return list<array{time:int,to:list<string>,subject:string,body:string}>
     */
    private static function fresh(mixed $stored): array
    {
        if (!is_array($stored)) {
            return [];
        }
        $oldest = time() - DAY_IN_SECONDS;

        /** @var list<array{time:int,to:list<string>,subject:string,body:string}> $entries voci scritte da send() */
        $entries = array_values(array_filter(
            $stored,
            static fn (mixed $entry): bool => is_array($entry) && (int) ($entry['time'] ?? 0) >= $oldest,
        ));

        return $entries;
    }

    /**
     * @param string|list<string> $to
     * @return list<string>
     */
    private static function recipients(string|array $to): array
    {
        $list = is_array($to) ? $to : explode(',', $to);
        $out = [];
        foreach ($list as $address) {
            $address = trim($address);
            if ($address !== '') {
                $out[] = $address;
            }
        }

        return $out;
    }
}
