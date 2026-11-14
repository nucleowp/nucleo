<?php

declare(strict_types=1);

namespace Nucleo\Forms;

defined('ABSPATH') || exit;

use Nucleo\Content\FieldValues;
use Nucleo\Content\ModelRepository;
use Nucleo\Extend\ActionContext;
use Nucleo\Extend\Components;
use Nucleo\OAuth\UrlGuard;
use Nucleo\Schema\JsonSchemaValidator;
use Nucleo\Schema\ValidationError;
use Nucleo\Support\HostResolver;
use Nucleo\Support\PinnedHttp;

/** Esegue le azioni dichiarate (tranne otp) nell'ordine. Un errore ferma le successive. */
final class FormActions
{
    public const SUBMISSION_TYPE = 'nucleo_submission';

    /** Meta key di un invio salvato: chiave del modulo (<doc>:<node>), valori, id del post di contesto. */
    public const META_FORM = '_nucleo_form';
    public const META_DATA = '_nucleo_data';
    public const META_CONTEXT = '_nucleo_context';

    /** Azioni gestite altrove: otp nel flusso del codice. */
    private const SKIPPED = ['otp'];
    private const POST_STATUSES = ['draft', 'pending'];

    /** Registra il tipo privato nucleo_submission. Idempotente. */
    public static function register(): void
    {
        if (did_action('init') > 0) {
            self::registerType();

            return;
        }
        add_action('init', static fn () => self::registerType());
    }

    /**
     * @return array{ok:bool, message:string, detail?:string} message vuoto se ok; detail (solo se non ok) è la causa dell'errore, per chi amministra
     */
    public static function run(FormSubmission $submission): array
    {
        $config = $submission->config;
        foreach ($config->actions as $action) {
            $type = (string) ($action['type'] ?? '');
            if (in_array($type, self::SKIPPED, true)) {
                continue;
            }
            $error = self::execute($submission, $action);
            if ($error !== null) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- dettaglio per l'amministratore, il visitatore vede un messaggio generico.
                error_log(sprintf('[nucleo] form %s, action %s: %s', $config->node, $type, $error));

                return ['ok' => false, 'message' => $config->labels['error'], 'detail' => $error];
            }
        }

        return ['ok' => true, 'message' => ''];
    }

    private static function registerType(): void
    {
        if (post_type_exists(self::SUBMISSION_TYPE)) {
            return;
        }
        register_post_type(self::SUBMISSION_TYPE, [
            'labels' => [
                'name' => __('Form submissions', 'nucleo'),
                'singular_name' => __('Form submission', 'nucleo'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => ['title'],
            'map_meta_cap' => true,
            'capabilities' => [
                'create_posts' => 'manage_options',
                'edit_posts' => 'manage_options',
                'edit_others_posts' => 'manage_options',
                'publish_posts' => 'manage_options',
                'read_private_posts' => 'manage_options',
                'delete_posts' => 'manage_options',
            ],
            'rewrite' => false,
            'query_var' => false,
            'has_archive' => false,
            'exclude_from_search' => true,
        ]);
    }

    /**
     * @param array<string,mixed> $action
     * @return string|null dettaglio dell'errore, null se l'azione è riuscita
     */
    private static function execute(FormSubmission $submission, array $action): ?string
    {
        return match ($action['type'] ?? null) {
            'save' => self::save($submission),
            'email' => self::email($submission, $action),
            'webhook' => self::webhook($submission, $action),
            'create-post' => self::createPost($submission, $action),
            'component' => self::component($submission, $action),
            default => 'unknown action type',
        };
    }

    /**
     * Azione component (livello 4): input validato contro inputSchema(), poi permitted(), poi run().
     * @param array<string,mixed> $action
     */
    private static function component(FormSubmission $submission, array $action): ?string
    {
        $name = self::text($action, 'name');
        $component = Components::instance()->action($name);
        if ($component === null) {
            return 'component action not registered';
        }

        $input = self::componentInput($submission, $action);
        $errors = (new JsonSchemaValidator())->validate($input, $component->inputSchema());
        if ($errors !== []) {
            return (string) wp_json_encode(array_map(static fn (ValidationError $e): array => $e->toArray(), $errors));
        }

        $context = new ActionContext(
            $submission->config->node,
            (int) $submission->doc,
            $submission->context === null ? 0 : $submission->context->ID,
            $submission->ip,
        );
        if (!$component->permitted($input, $context)) {
            return 'component action not permitted';
        }
        $result = $component->run($input, $context);

        return $result->ok ? null : ($result->message !== '' ? $result->message : 'component action failed');
    }

    /**
     * Con action.input: le stringhe passano da fill(). Senza input: tutti i valori dei campi.
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private static function componentInput(FormSubmission $submission, array $action): array
    {
        if (!isset($action['input']) || !is_array($action['input'])) {
            return $submission->values;
        }
        $input = [];
        foreach ($action['input'] as $key => $template) {
            $input[(string) $key] = is_string($template) ? $submission->fill($template) : $template;
        }

        return $input;
    }

    private static function save(FormSubmission $submission): ?string
    {
        $id = wp_insert_post([
            'post_type' => self::SUBMISSION_TYPE,
            'post_status' => 'private',
            'post_title' => $submission->config->node . ' – ' . current_time('Y-m-d H:i:s'),
            'meta_input' => [
                self::META_FORM => self::formKey($submission),
                self::META_DATA => $submission->values,
                self::META_CONTEXT => $submission->context === null ? 0 : $submission->context->ID,
            ],
        ], true);

        return is_wp_error($id) ? $id->get_error_message() : null;
    }

    /** @param array<string,mixed> $action */
    private static function email(FormSubmission $submission, array $action): ?string
    {
        // Senza "to" il destinatario è l'amministratore del sito.
        $ref = self::text($action, 'to');
        $to = self::recipient($submission, $ref === '' ? 'admin' : $ref);
        if ($to === null) {
            return 'invalid recipient';
        }
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $replyTo = self::fieldEmail($submission, self::text($action, 'replyTo'));
        if ($replyTo !== null) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        $sent = DevMail::send(
            $to,
            self::oneLine($submission->fill(self::text($action, 'subject'))),
            $submission->fill(self::text($action, 'body')),
            $headers,
        );

        return $sent ? null : 'wp_mail did not send the message';
    }

    /** @param array<string,mixed> $action */
    private static function webhook(FormSubmission $submission, array $action): ?string
    {
        $url = self::text($action, 'url');
        $guard = new UrlGuard([HostResolver::class, 'resolve']);
        if (!$guard->isSafePublicUrl($url)) {
            return 'unsafe webhook URL';
        }
        // IP fissato e nessun redirect: il webhook arriva solo all'host verificato.
        $response = PinnedHttp::request('POST', $url, [
            'timeout' => 5,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string) wp_json_encode([
                'form' => self::formKey($submission),
                'fields' => $submission->values,
                'context' => $submission->context === null ? null : $submission->context->ID,
            ]),
        ], $guard);
        if (is_wp_error($response)) {
            return $response->get_error_message();
        }
        $code = (int) wp_remote_retrieve_response_code($response);

        return $code >= 400 ? 'HTTP status ' . $code : null;
    }

    /** @param array<string,mixed> $action */
    private static function createPost(FormSubmission $submission, array $action): ?string
    {
        $postType = self::text($action, 'postType');
        $status = self::text($action, 'status');
        if (!in_array($postType, self::modelPostTypes(), true)) {
            return 'post type not in the model';
        }
        if (!in_array($status, self::POST_STATUSES, true)) {
            return 'status not allowed';
        }
        $id = wp_insert_post([
            'post_type' => $postType,
            'post_status' => $status,
            'post_title' => $submission->fill(self::text($action, 'title')),
        ], true);
        if (is_wp_error($id)) {
            return $id->get_error_message();
        }

        $values = [];
        $fields = $action['fields'] ?? [];
        if (is_array($fields)) {
            foreach ($fields as $key => $template) {
                if (is_string($key) && is_string($template)) {
                    $values[$key] = $submission->fill($template);
                }
            }
        }
        if ($values === []) {
            return null;
        }
        $result = FieldValues::set('post', $id, $values);
        if ($result['ok']) {
            return null;
        }
        // Campi non validi: niente contenuto a metà.
        wp_delete_post($id, true);

        return (string) wp_json_encode($result['errors'] ?? []);
    }

    /** Destinatario: admin, field:<nome> (email valida del campo) o indirizzo letterale. */
    private static function recipient(FormSubmission $submission, string $to): ?string
    {
        if ($to === '') {
            return null;
        }
        if ($to === 'admin') {
            $admin = (string) get_option('admin_email');

            return is_email($admin) !== false ? $admin : null;
        }
        if (str_starts_with($to, 'field:')) {
            return self::fieldEmail($submission, $to);
        }

        return is_email($to) !== false ? $to : null;
    }

    /** Valore di un campo "field:<nome>" se è un'email valida, altrimenti null. */
    private static function fieldEmail(FormSubmission $submission, string $ref): ?string
    {
        if (!str_starts_with($ref, 'field:')) {
            return null;
        }
        $value = $submission->values[substr($ref, 6)] ?? null;

        return is_string($value) && is_email($value) !== false ? $value : null;
    }

    /** @return list<string> */
    private static function modelPostTypes(): array
    {
        return array_column(ModelRepository::model()['postTypes'], 'slug');
    }

    /** @param array<string,mixed> $action */
    private static function text(array $action, string $key): string
    {
        $value = $action[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    private static function formKey(FormSubmission $submission): string
    {
        return $submission->doc . ':' . $submission->config->node;
    }

    /** Oggetto della mail su una riga sola. */
    private static function oneLine(string $text): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $text));
    }
}
