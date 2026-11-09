<?php

declare(strict_types=1);

namespace Nucleo\Forms;

use Nucleo\Support\AtomicCounter;

/**
 * Codici OTP: ticket in transient con hash del codice, valori dell'invio e scadenza.
 * I tentativi e il consumo del ticket sono contatori atomici, non campi del transient: richieste in parallelo non li perdono.
 * Ticket "step": verifica del codice a metà modulo (confirm), poi prova di verifica usata una sola volta all'invio finale (redeem).
 */
final class OtpTickets
{
    private const PREFIX = 'nucleo_otp_';
    private const ATTEMPTS = 5;
    private const DEFAULT_TTL = 15;
    private const MIN_TTL = 5;
    private const MAX_TTL = 30;
    private const VERIFIED_TTL = 30 * MINUTE_IN_SECONDS;

    /**
     * Crea un ticket, invia il codice all'indirizzo e restituisce il ticket. null se l'email non parte.
     * @param array<string,mixed> $otp azione otp {field, subject?, body?, ttl?}
     */
    public static function issue(FormSubmission $submission, array $otp): ?string
    {
        $field = $otp['field'] ?? null;
        $address = is_string($field) ? ($submission->values[$field] ?? null) : null;
        if (!is_string($address) || is_email($address) === false) {
            return null;
        }

        $code = sprintf('%06d', random_int(0, 999999));
        $ticket = wp_generate_password(24, false);
        $name = self::key($ticket);
        $ttl = self::ttl($otp);
        set_transient($name, [
            'doc' => $submission->doc,
            'node' => $submission->config->node,
            'hash' => self::hash($code),
            'values' => $submission->values,
            'context' => $submission->context === null ? 0 : $submission->context->ID,
            'until' => time() + $ttl,
        ], $ttl);

        if (!self::sendCode($address, $otp, $code, $submission->context)) {
            delete_transient($name);

            return null;
        }

        return $ticket;
    }

    /**
     * Ticket "step" per un indirizzo, non ancora verificato. Il codice va per email; null se l'email non parte.
     * @param array<string,mixed> $otp azione otp {field, subject?, body?, ttl?}
     */
    public static function issueForAddress(int $doc, string $node, string $address, array $otp, ?\WP_Post $context, string $secret): ?string
    {
        if (is_email($address) === false) {
            return null;
        }

        $code = sprintf('%06d', random_int(0, 999999));
        $ticket = wp_generate_password(24, false);
        $name = self::key($ticket);
        $ttl = self::ttl($otp);
        set_transient($name, [
            'mode' => 'step',
            'doc' => $doc,
            'node' => $node,
            'hash' => self::hash($code),
            'address' => self::hash(self::normalizeAddress($address)),
            'context' => $context === null ? 0 : $context->ID,
            'verified' => false,
            // Segreto del cookie HttpOnly emesso con il ticket: il ticket da solo non basta a verificare né a usare la prova.
            'secret' => self::hash($secret),
            'until' => time() + $ttl,
        ], $ttl);

        if (!self::sendCode($address, $otp, $code, $context)) {
            delete_transient($name);

            return null;
        }

        return $ticket;
    }

    /**
     * Verifica il codice. Valori e post di contesto salvati se valido; 'expired' se il ticket non c'è più, se i tentativi
     * sono oltre il limite o se il ticket è già stato usato; 'wrong' altrimenti.
     * Il ticket valido si cancella; il primo consumo vince anche con richieste in parallelo.
     * I ticket "step" non si verificano qui: restituiscono 'expired'.
     * @return array{values: array<string,string|list<string>>, context: int}|'expired'|'wrong'
     */
    public static function verify(int $doc, string $node, string $ticket, string $code): array|string
    {
        $name = self::key($ticket);
        $state = get_transient($name);
        if (!is_array($state) || ($state['doc'] ?? null) !== $doc || ($state['node'] ?? null) !== $node) {
            return 'expired';
        }
        if (($state['mode'] ?? null) === 'step') {
            return 'expired';
        }
        $remaining = (int) ($state['until'] ?? 0) - time();
        if ($remaining < 1) {
            delete_transient($name);

            return 'expired';
        }

        // Ogni richiesta conta un tentativo, giusta o sbagliata: dal sesto in poi il ticket scade.
        if (AtomicCounter::incr('otp-try|' . $ticket, $remaining) > self::ATTEMPTS) {
            delete_transient($name);

            return 'expired';
        }

        $hash = is_string($state['hash'] ?? null) ? $state['hash'] : '';
        if (!hash_equals($hash, self::hash($code))) {
            return 'wrong';
        }

        // Consumo unico: solo la prima richiesta con il codice giusto ottiene il valore 1.
        if (AtomicCounter::incr('otp-used|' . $ticket, $remaining) !== 1) {
            return 'expired';
        }
        delete_transient($name);

        return [
            'values' => is_array($state['values'] ?? null) ? $state['values'] : [],
            'context' => is_int($state['context'] ?? null) ? $state['context'] : 0,
        ];
    }

    /**
     * Verifica il codice di un ticket "step". Codice giusto: il ticket diventa verificato e resta valido 30 minuti.
     * Stesso limite di tentativi di verify(). 'expired' per ticket classici, scaduti o oltre il limite.
     * @return 'ok'|'expired'|'wrong'
     */
    public static function confirm(int $doc, string $node, string $ticket, string $code, string $secret): string
    {
        $name = self::key($ticket);
        $state = get_transient($name);
        if (!self::isStep($state, $doc, $node) || !self::sameSecret($state, $secret)) {
            return 'expired';
        }
        $remaining = (int) $state['until'] - time();
        if ($remaining < 1) {
            delete_transient($name);

            return 'expired';
        }

        if (AtomicCounter::incr('otp-try|' . $ticket, $remaining) > self::ATTEMPTS) {
            delete_transient($name);

            return 'expired';
        }

        $hash = is_string($state['hash'] ?? null) ? $state['hash'] : '';
        if (!hash_equals($hash, self::hash($code))) {
            return 'wrong';
        }

        // La prima verifica fissa la scadenza della prova; una conferma ripetuta non la rinnova.
        if (($state['verified'] ?? null) !== true) {
            $state['verified'] = true;
            $state['until'] = time() + self::VERIFIED_TTL;
            set_transient($name, $state, self::VERIFIED_TTL);
        }

        return 'ok';
    }

    /**
     * Prova di verifica per l'invio finale: vale solo per un ticket "step" verificato, non scaduto, dello stesso
     * documento, nodo e indirizzo. Il consumo è unico: il secondo uso restituisce false.
     */
    public static function redeem(int $doc, string $node, string $ticket, string $address, string $secret): bool
    {
        $name = self::key($ticket);
        $state = get_transient($name);
        if (!self::isStep($state, $doc, $node) || !self::sameSecret($state, $secret) || ($state['verified'] ?? null) !== true) {
            return false;
        }
        $remaining = (int) $state['until'] - time();
        if ($remaining < 1) {
            delete_transient($name);

            return false;
        }

        $stored = is_string($state['address'] ?? null) ? $state['address'] : '';
        if (!hash_equals($stored, self::hash(self::normalizeAddress($address)))) {
            return false;
        }

        if (AtomicCounter::incr('otp-used|' . $ticket, $remaining) !== 1) {
            return false;
        }
        delete_transient($name);

        return true;
    }

    /**
     * Email con il codice: oggetto su una riga, corpo con i segnaposto. false se wp_mail non parte.
     * @param array<string,mixed> $otp
     */
    private static function sendCode(string $address, array $otp, string $code, ?\WP_Post $context): bool
    {
        $subject = is_string($otp['subject'] ?? null) && $otp['subject'] !== ''
            ? $otp['subject']
            : __('Your verification code', 'nucleo');
        $body = is_string($otp['body'] ?? null) && $otp['body'] !== '' ? $otp['body'] : 'Your code: {code}';

        return DevMail::send(
            $address,
            trim(str_replace(["\r", "\n"], ' ', self::fillCode($subject, $code, $context))),
            self::fillCode($body, $code, $context),
            ['Content-Type: text/plain; charset=UTF-8'],
        );
    }

    /** Ticket "step" di questo documento e nodo. @param mixed $state */
    private static function isStep(mixed $state, int $doc, string $node): bool
    {
        return is_array($state)
            && ($state['mode'] ?? null) === 'step'
            && ($state['doc'] ?? null) === $doc
            && ($state['node'] ?? null) === $node;
    }

    /**
     * Segreto del cookie uguale a quello del ticket. Segreto vuoto: mai valido.
     * @param array<string,mixed> $state
     */
    private static function sameSecret(array $state, string $secret): bool
    {
        $stored = is_string($state['secret'] ?? null) ? $state['secret'] : '';

        return $secret !== '' && $stored !== '' && hash_equals($stored, self::hash($secret));
    }

    /** Indirizzo per confronti e hash: senza spazi ai bordi, in minuscole. */
    private static function normalizeAddress(string $address): string
    {
        return strtolower(trim($address));
    }

    /**
     * Email OTP: solo {code}, {site.name}, {post.title}, {post.url}. Gli altri segnaposto, come {fields} o {field:x}, diventano vuoti.
     * Una sola passata: il valore inserito non viene riletto.
     */
    private static function fillCode(string $template, string $code, ?\WP_Post $context): string
    {
        $tokens = [
            'code' => $code,
            'site.name' => (string) get_bloginfo('name'),
            'post.title' => $context === null ? '' : (string) $context->post_title,
            'post.url' => $context === null ? '' : (string) get_permalink($context),
        ];

        return (string) preg_replace_callback(
            '/\{([^{}]+)\}/',
            static fn (array $match): string => $tokens[$match[1]] ?? '',
            $template,
        );
    }

    private static function key(string $ticket): string
    {
        return self::PREFIX . md5($ticket);
    }

    private static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, wp_salt('nonce'));
    }

    /**
     * Durata in secondi: minuti dell'azione tra 5 e 30, default 15.
     * @param array<string,mixed> $otp
     */
    private static function ttl(array $otp): int
    {
        $minutes = is_numeric($otp['ttl'] ?? null) ? (int) $otp['ttl'] : self::DEFAULT_TTL;

        return max(self::MIN_TTL, min(self::MAX_TTL, $minutes)) * MINUTE_IN_SECONDS;
    }
}
