<?php

declare(strict_types=1);

namespace Nucleo\Storage;

defined('ABSPATH') || exit;

use Nucleo\OAuth\CurrentToken;

/**
 * Lettura e scrittura del documento e dello storico nei meta del post.
 * _nucleo_doc è JSON salvato con wp_slash, che update_post_meta toglie: nel database c'è il JSON così com'è.
 */
final class DocumentRepository
{
    private const HISTORY_LIMIT = 20;

    /** @return array{document:?array<string,mixed>,revision:int}|null null se il post non esiste */
    public function find(int $postId): ?array
    {
        if (!get_post($postId)) {
            return null;
        }

        $raw = get_post_meta($postId, '_nucleo_doc', true);
        // Niente wp_unslash: toglierebbe le barre del JSON (\" dentro un href) e il documento non si leggerebbe più.
        $doc = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return [
            'document' => is_array($doc) ? $doc : null,
            'revision' => (int) get_post_meta($postId, '_nucleo_rev', true),
        ];
    }

    /**
     * Scrive documento, revisione e runtime, e aggiunge la versione allo storico.
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $rt
     */
    public function store(int $postId, array $doc, int $rev, array $rt): void
    {
        // update_post_meta() toglie le barre rovesciate: runtime e storico passano con wp_slash().
        update_post_meta($postId, '_nucleo_rt', wp_slash($rt));
        update_post_meta($postId, '_nucleo_doc', wp_slash(wp_json_encode($doc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
        update_post_meta($postId, '_nucleo_rev', $rev);

        $history = $this->history($postId);
        $history[] = ['rev' => $rev, 'doc' => $doc, 'time' => time(), 'user' => get_current_user_id(), 'client' => CurrentToken::clientId() ?? ''];
        update_post_meta($postId, '_nucleo_hist', wp_slash(array_slice($history, -self::HISTORY_LIMIT)));
    }

    /** @return list<array{rev:int,doc:array<string,mixed>,time:int,user:int,client?:string}> */
    public function history(int $postId): array
    {
        $history = get_post_meta($postId, '_nucleo_hist', true);

        return is_array($history) ? array_values($history) : [];
    }

    /**
     * Documento della versione richiesta. Non scrive: il chiamante decide se salvarlo.
     * @return array<string,mixed>|null
     */
    public function restore(int $postId, int $rev): ?array
    {
        foreach ($this->history($postId) as $version) {
            if ((int) $version['rev'] === $rev) {
                return $version['doc'];
            }
        }

        return null;
    }
}
