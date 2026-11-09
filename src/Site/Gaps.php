<?php

declare(strict_types=1);

namespace Nucleo\Site;

use WP_Error;

/** Lacune segnalate dagli agenti (cosa il design chiede e Nucleo non sa fare), nell'opzione nucleo_gaps. */
final class Gaps
{
    private const OPTION = 'nucleo_gaps';

    private const MAX_GAPS = 200;

    private const MAX_NEED = 500;

    private const MAX_WORKAROUND = 1000;

    private const MAX_PAGE = 200;

    /** Nome di un blocco Nucleo: nc/ seguito da lettere minuscole, cifre e trattini. */
    private const BLOCK_PATTERN = '/^[a-z][a-z0-9-]*\/[a-z0-9-]+$/D';

    /**
     * Registra una lacuna. Stesso blocco e stesso bisogno (confronto senza maiuscole, spazi ai bordi tolti) aggiungono
     * un'occorrenza e aggiornano ultima data e workaround. Le più vecchie oltre MAX_GAPS si scartano.
     * @return array{count:int}|WP_Error il numero di occorrenze di questa lacuna
     */
    public static function report(string $need, string $block, string $workaround, string $page): array|WP_Error
    {
        $need = trim($need);
        $block = trim($block);
        $workaround = trim($workaround);
        $page = trim($page);

        $needLength = mb_strlen($need);
        if ($needLength < 1 || $needLength > self::MAX_NEED) {
            /* translators: %d: maximum length of the need. */
            return new WP_Error('invalid_input', sprintf(__('"need" must be 1 to %d characters.', 'nucleo'), self::MAX_NEED));
        }
        if ($block !== '' && preg_match(self::BLOCK_PATTERN, $block) !== 1) {
            return new WP_Error('invalid_input', __('"block" must be a Nucleo block name such as nc/section-ref.', 'nucleo'));
        }
        if (mb_strlen($workaround) > self::MAX_WORKAROUND) {
            /* translators: %d: maximum length of the workaround. */
            return new WP_Error('invalid_input', sprintf(__('"workaround" must be at most %d characters.', 'nucleo'), self::MAX_WORKAROUND));
        }
        if (mb_strlen($page) > self::MAX_PAGE) {
            /* translators: %d: maximum length of the page. */
            return new WP_Error('invalid_input', sprintf(__('"page" must be at most %d characters.', 'nucleo'), self::MAX_PAGE));
        }

        $today = gmdate('Y-m-d');
        $gaps = self::stored();
        $key = mb_strtolower($need);

        $gap = null;
        foreach ($gaps as $index => $existing) {
            if ($existing['block'] === $block && mb_strtolower($existing['need']) === $key) {
                $gap = $existing;
                unset($gaps[$index]);
                break;
            }
        }

        if ($gap !== null) {
            $gap['count']++;
            $gap['last'] = $today;
            $gap['workaround'] = $workaround;
        } else {
            $gap = [
                'need' => $need,
                'block' => $block,
                'workaround' => $workaround,
                'page' => $page,
                'count' => 1,
                'first' => $today,
                'last' => $today,
            ];
        }

        // Ordine per ultimo aggiornamento: in testa la più vecchia, in coda la più recente.
        $gaps[] = $gap;
        $gaps = array_values($gaps);
        if (count($gaps) > self::MAX_GAPS) {
            $gaps = array_slice($gaps, count($gaps) - self::MAX_GAPS);
        }

        update_option(self::OPTION, $gaps, false);

        return ['count' => $gap['count']];
    }

    /**
     * Lacune salvate, dalla più recente.
     * @return list<array{need:string,block:string,workaround:string,page:string,count:int,first:string,last:string}>
     */
    public static function all(): array
    {
        return array_reverse(self::stored());
    }

    /**
     * Lacune dall'opzione, scartando le voci malformate.
     * @return list<array{need:string,block:string,workaround:string,page:string,count:int,first:string,last:string}>
     */
    private static function stored(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return [];
        }

        $gaps = [];
        foreach ($stored as $gap) {
            if (
                is_array($gap)
                && is_string($gap['need'] ?? null)
                && is_string($gap['block'] ?? null)
                && is_string($gap['workaround'] ?? null)
                && is_string($gap['page'] ?? null)
                && is_int($gap['count'] ?? null)
                && is_string($gap['first'] ?? null)
                && is_string($gap['last'] ?? null)
            ) {
                $gaps[] = $gap;
            }
        }

        return $gaps;
    }
}
