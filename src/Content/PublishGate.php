<?php

declare(strict_types=1);

namespace Nucleo\Content;

use Nucleo\Compiler\DocumentService;
use Nucleo\Compiler\SaveOptions;
use Nucleo\Services;

/**
 * Il controllo di qualità bloccante vale anche quando una pagina Nucleo va online dall'editor di WordPress
 * (editor a blocchi, editor classico, pannello pagina, programmazione): la stessa verifica di doc-publish, in dry-run.
 */
final class PublishGate
{
    private const PUBLIC_STATUSES = ['publish', 'future', 'private'];
    private const NOTICE = 'nucleo_publish_blocked_';

    public static function register(): void
    {
        add_filter('wp_insert_post_data', [self::class, 'filterData'], 10, 2);
        add_action('admin_notices', [self::class, 'notice']);
        add_action('rest_api_init', static function (): void {
            foreach (get_post_types(['show_in_rest' => true]) as $type) {
                add_filter('rest_pre_insert_' . $type, [self::class, 'filterRest'], 20, 2);
            }
        });
    }

    /** REST: rifiuta la richiesta con gli errori, così l'editor li mostra. */
    public static function filterRest(\stdClass|\WP_Error $prepared, \WP_REST_Request $request): \stdClass|\WP_Error
    {
        if ($prepared instanceof \WP_Error) {
            return $prepared;
        }
        $postId = (int) ($prepared->ID ?? 0);
        $status = is_string($prepared->post_status ?? null) ? $prepared->post_status : '';
        $blocked = self::blocked($postId, $status);

        return $blocked === null ? $prepared : new \WP_Error('quality_blocking', $blocked, ['status' => 409]);
    }

    /**
     * Editor classico e altri percorsi: lo stato resta quello precedente.
     * @param array<string,mixed> $data
     * @param array<string,mixed> $postarr
     * @return array<string,mixed>
     */
    public static function filterData(array $data, array $postarr): array
    {
        $postId = (int) ($postarr['ID'] ?? 0);
        $status = is_string($data['post_status'] ?? null) ? $data['post_status'] : '';
        $blocked = self::blocked($postId, $status);
        if ($blocked !== null) {
            $previous = get_post_status($postId);
            $data['post_status'] = is_string($previous) && $previous !== '' ? $previous : 'draft';
            // L'editor classico non riceve errori dal filtro: il motivo si mostra al caricamento successivo.
            set_transient(self::NOTICE . get_current_user_id(), $blocked, 300);
        }

        return $data;
    }

    /** Avviso dopo un tentativo di pubblicazione bloccato dall'editor classico o da un altro percorso senza REST. */
    public static function notice(): void
    {
        $key = self::NOTICE . get_current_user_id();
        $message = get_transient($key);
        if (!is_string($message) || $message === '') {
            return;
        }
        delete_transient($key);
        printf(
            '<div class="notice notice-error is-dismissible"><p>%s %s</p></div>',
            esc_html__('Nucleo did not publish this page.', 'nucleo'),
            esc_html($message),
        );
    }

    /** Messaggio del blocco, oppure null se la transizione è ammessa o non riguarda un documento Nucleo. */
    public static function blocked(int $postId, string $status): ?string
    {
        if ($postId <= 0 || DocumentService::writing() || !in_array($status, self::PUBLIC_STATUSES, true)) {
            return null;
        }
        // Già online: ogni salvataggio Nucleo passa già dal controllo.
        if (in_array(get_post_status($postId), self::PUBLIC_STATUSES, true) || !EditorMode::isActive($postId)) {
            return null;
        }
        $found = Services::documents()->get($postId);
        if ($found === null) {
            return null;
        }
        $result = Services::documents()->save($postId, $found['document'], new SaveOptions(publish: true, dryRun: true));
        if ($result->ok) {
            return null;
        }

        return is_string($result->error['message'] ?? null) ? $result->error['message'] : __('Blocking quality issues: fix them or acknowledge them.', 'nucleo');
    }
}
