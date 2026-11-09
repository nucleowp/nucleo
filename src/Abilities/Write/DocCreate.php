<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Compiler\SaveOptions;
use Nucleo\Services;
use Nucleo\Storage\AuditLog;

/** nucleo/doc-create: crea una pagina o un post da un documento Nucleo. */
final class DocCreate implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/doc-create';
    }

    public function args(): array
    {
        return [
            'label' => __('Create document', 'nucleo'),
            'description' => 'Creates a page, post, layout or section from a Nucleo document; without postType the type follows document.kind (layout and popup: nucleo_layout, section: nucleo_section). With postId it attaches the document to an existing content without a Nucleo document; on a published content an OAuth token also needs the scope nucleo:publish. The slug comes from slug, document.wp.slug or document.meta.slug, then from the title, also for drafts; a taken slug gets a suffix and a warning. Quality is checked on the page composed with the site layouts it will receive (header, footer, single or product layout), also for a new content: an h1 in the header counts. With publish true it publishes, but blocking quality issues require acknowledge. With dryRun true it does not save. Returns postId, revision and slug.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['document'],
                'properties' => [
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'Required without postId.'],
                    'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$', 'description' => 'Slug of the new content (a-z, 0-9, -). Without it the slug comes from document.wp.slug or document.meta.slug (normalized as WordPress does), then from the title. If the slug is taken, a suffix is added: the output slug and a warning say so.'],
                    'postId' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Existing content without a Nucleo document: the document is attached to it. Type, title and status stay as they are.'],
                    'document' => ['type' => 'object', 'description' => 'Nucleo document in compact or full form.'],
                    'postType' => ['type' => 'string', 'enum' => ['page', 'post', 'nucleo_layout', 'nucleo_section'], 'description' => 'Without it the type comes from document.kind: layout and popup create a nucleo_layout, section a nucleo_section, any other kind a page. page and post refuse the kinds layout, popup and section.'],
                    'publish' => ['type' => 'boolean', 'default' => false],
                    'dryRun' => ['type' => 'boolean', 'default' => false],
                    'acknowledge' => [
                        'type' => 'array',
                        'description' => 'Quality issues acknowledged, with a reason.',
                        'items' => [
                            'type' => 'object',
                            'required' => ['rule', 'node', 'reason'],
                            'properties' => [
                                'rule' => ['type' => 'string'],
                                'node' => ['type' => 'string'],
                                'reason' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'postId' => ['type' => 'integer', 'minimum' => 0],
                    'revision' => ['type' => 'integer', 'minimum' => 0],
                    'slug' => ['type' => 'string', 'description' => 'Slug the content received.'],
                    'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'errors' => ['type' => 'array'],
                    'quality' => ['type' => 'object'],
                    'error' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
        ];
    }

    public function execute(array $input): array|\WP_Error
    {
        if (isset($input['postId'])) {
            return self::start($input);
        }
        $title = $input['title'] ?? null;
        $document = $input['document'] ?? null;
        $postType = self::postType($input);
        $publish = $input['publish'] ?? false;
        $dryRun = $input['dryRun'] ?? false;
        $acknowledge = self::acknowledgements($input['acknowledge'] ?? null);
        if (
            !is_string($title) || $title === '' || !is_array($document)
            || !in_array($postType, ['page', 'post', 'nucleo_layout', 'nucleo_section'], true) || !self::kindMatches($postType, $document)
            || !is_bool($publish) || !is_bool($dryRun) || $acknowledge === null
        ) {
            return new \WP_Error('invalid_input', __('Invalid title, document, postType, publish, dryRun or acknowledge fields.', 'nucleo'));
        }
        $slug = self::requestedSlug($input, $document);
        if ($slug === false) {
            return new \WP_Error('invalid_input', __('Invalid slug: use lowercase letters, digits and hyphens, for example "about-us".', 'nucleo'));
        }

        $result = Services::documents()->create($document, new SaveOptions(
            publish: $publish,
            dryRun: $dryRun,
            acknowledge: $acknowledge,
            title: $title,
            postType: $postType,
            slug: $slug ?? '',
        ));
        $summary = $result->ok ? ($dryRun ? 'dry-run' : ($publish ? 'publish' : 'draft')) : ($result->error['code'] ?? 'error');
        AuditLog::record('doc-create', $result->postId, $result->ok, $summary);

        $out = $result->toArray();
        if ($result->ok && !$dryRun && $result->postId > 0) {
            $out['slug'] = (string) get_post_field('post_name', $result->postId);
            // Slug già usato: WordPress aggiunge un suffisso. L'agente lo deve sapere per i link e i file locali.
            if ($slug !== null && $out['slug'] !== $slug) {
                /* translators: 1: requested slug, 2: slug assigned by WordPress. */
                $out['warnings'] = [sprintf(__('The slug %1$s is already used: the content got %2$s.', 'nucleo'), $slug, $out['slug'])];
            }
        }

        return $out;
    }

    /**
     * Slug richiesto: input slug, poi document.wp.slug o document.meta.slug. Null se manca, false se non valido.
     * @param array<string,mixed> $input
     * @param array<string,mixed> $document
     */
    public static function requestedSlug(array $input, array $document): string|false|null
    {
        if (array_key_exists('slug', $input)) {
            $slug = $input['slug'];

            return is_string($slug) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1 ? $slug : false;
        }
        // Lo slug del documento (per esempio da site-export) segue le regole di WordPress: si normalizza, non si rifiuta.
        $slug = $document['wp']['slug'] ?? $document['meta']['slug'] ?? null;
        if (!is_string($slug) || $slug === '') {
            return null;
        }
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1) {
            return $slug;
        }
        $clean = sanitize_title($slug);

        return $clean === '' ? null : $clean;
    }

    public function permitted(array $input): bool
    {
        if (isset($input['postId'])) {
            $postId = is_int($input['postId']) ? $input['postId'] : 0;

            // Contenuto inesistente: l'esecuzione risponde not_found.
            return get_post($postId) === null ? current_user_can('edit_posts') : current_user_can('edit_post', $postId);
        }
        $postType = self::postType($input);
        if (in_array($postType, ['nucleo_layout', 'nucleo_section'], true)) {
            return current_user_can('edit_theme_options');
        }

        $isPost = $postType === 'post';
        if (!current_user_can($isPost ? 'edit_posts' : 'edit_pages')) {
            return false;
        }

        return ($input['publish'] ?? false) !== true || current_user_can($isPost ? 'publish_posts' : 'publish_pages');
    }

    /**
     * doc-create con postId: primo documento di un contenuto esistente.
     * @param array<string,mixed> $input
     * @return array<string,mixed>|\WP_Error
     */
    private static function start(array $input): array|\WP_Error
    {
        $postId = $input['postId'];
        $document = $input['document'] ?? null;
        $dryRun = $input['dryRun'] ?? false;
        $acknowledge = self::acknowledgements($input['acknowledge'] ?? null);
        if (!is_int($postId) || !is_array($document) || ($document['kind'] ?? 'page') !== 'page' || !is_bool($dryRun) || $acknowledge === null) {
            return new \WP_Error('invalid_input', __('Invalid postId, document, dryRun or acknowledge fields.', 'nucleo'));
        }

        $result = Services::documents()->start($postId, $document, new SaveOptions(dryRun: $dryRun, acknowledge: $acknowledge));
        AuditLog::record('doc-create', $result->postId, $result->ok, $result->ok ? ($dryRun ? 'dry-run' : 'attach') : ($result->error['code'] ?? 'error'));

        return $result->toArray();
    }

    /**
     * Tipo di contenuto: postType se indicato, altrimenti ricavato dal kind del documento (layout e popup vanno in
     * nucleo_layout, section in nucleo_section, il resto in page).
     * @param array<string,mixed> $input
     */
    private static function postType(array $input): mixed
    {
        if (array_key_exists('postType', $input)) {
            return $input['postType'];
        }
        $kind = is_array($input['document'] ?? null) ? ($input['document']['kind'] ?? null) : null;

        return match ($kind) {
            'layout', 'popup' => 'nucleo_layout',
            'section' => 'nucleo_section',
            default => 'page',
        };
    }

    /**
     * I documenti dei layout hanno kind layout o popup, quelli delle sezioni kind section; pagine e articoli non
     * accettano questi kind (un layout salvato come pagina non si applica mai).
     * @param array<string,mixed> $document
     */
    private static function kindMatches(string $postType, array $document): bool
    {
        $kind = $document['kind'] ?? null;
        $kinds = match ($postType) {
            'nucleo_layout' => ['layout', 'popup'],
            'nucleo_section' => ['section'],
            default => null,
        };

        return $kinds === null ? !in_array($kind, ['layout', 'popup', 'section'], true) : in_array($kind, $kinds, true);
    }

    /** @return list<array{rule:string,node:string,reason:string}>|null null se la forma non è valida */
    private static function acknowledgements(mixed $raw): ?array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }

        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !is_string($item['rule'] ?? null) || !is_string($item['node'] ?? null) || !is_string($item['reason'] ?? null)) {
                return null;
            }
            $out[] = ['rule' => $item['rule'], 'node' => $item['node'], 'reason' => $item['reason']];
        }

        return $out;
    }
}
