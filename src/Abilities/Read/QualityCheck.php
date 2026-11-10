<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Quality\PostChecks;
use Nucleo\Quality\QualityReport;
use Nucleo\Compiler\DocumentService;
use Nucleo\Services;

/** nucleo/quality-check: report qualità di un documento salvato (per id o per URL) o passato in input. Non scrive. */
final class QualityCheck implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/quality-check';
    }

    public function args(): array
    {
        return [
            'label' => __('Quality check', 'nucleo'),
            'description' => 'Quality report (SEO, accessibility, performance) of a saved document (postId or url of its page) or of one passed as input (document). A document without postId is checked inside the site layouts of postType (default page), as doc-create does. Issues acknowledged with acknowledge do not block. For a document not yet saved nucleo/doc-validate returns the same quality report together with the schema errors. For issues across pages (duplicate titles, orphan pages) use nucleo/quality-scan.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'postId' => ['type' => 'integer', 'minimum' => 1],
                    'url' => ['type' => 'string', 'minLength' => 1, 'description' => 'URL of the page, as alternative to postId.'],
                    'document' => ['type' => 'object', 'description' => 'Nucleo document to check without saving it.'],
                    'postType' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,20}$', 'description' => 'Post type the new content will have (default page). A content without postId is checked inside the layouts of that type, as doc-create and site-import do: post gets the single layout, product the product layout.'],
                    'acknowledge' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['rule', 'node', 'reason'],
                            'properties' => [
                                'rule' => ['type' => 'string', 'minLength' => 1],
                                'node' => ['type' => 'string', 'minLength' => 1],
                                'reason' => ['type' => 'string', 'minLength' => 1],
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean', 'description' => 'true if the document is valid and the check was run.'],
                    'errors' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'quality' => [
                        'type' => 'object',
                        'properties' => [
                            'score' => ['type' => 'object'],
                            'blocking' => ['type' => 'boolean'],
                            'issues' => ['type' => 'array', 'items' => ['type' => 'object']],
                        ],
                    ],
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
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    public function execute(array $input): array|\WP_Error
    {
        $postId = $input['postId'] ?? null;
        $url = $input['url'] ?? null;
        $document = $input['document'] ?? null;
        $acknowledge = self::normalizeAcks($input['acknowledge'] ?? []);
        if ((int) ($postId !== null) + (int) ($url !== null) + (int) ($document !== null) !== 1) {
            return new \WP_Error('invalid_input', __('Provide one of postId, url or document.', 'nucleo'));
        }

        if ($url !== null) {
            if (!is_string($url) || $url === '') {
                return new \WP_Error('invalid_input', __('The "url" field must be a string with a value.', 'nucleo'));
            }
            $postId = url_to_postid($url);
            if ($postId === 0) {
                return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => __('No page found at this URL.', 'nucleo')]];
            }
        }

        if ($postId !== null) {
            if (!is_int($postId)) {
                return new \WP_Error('invalid_input', __('postId must be an integer.', 'nucleo'));
            }
            $found = Services::documents()->get($postId);
            if ($found === null) {
                /* translators: %d: post ID of the requested document. */
                return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => sprintf(__('Document not found: %d.', 'nucleo'), $postId)]];
            }
            $key = 'page:' . $postId;
            $doc = $found['document'];
        } else {
            if (!is_array($document)) {
                return new \WP_Error('invalid_input', __('The "document" field must be an object.', 'nucleo'));
            }
            $key = 'page:0';
            $doc = $document;
        }

        $expansion = Services::documents()->prepare($doc, is_int($postId) ? $postId : 0);
        $doc = $expansion->doc;
        $errors = Services::documents()->check($expansion);
        if ($errors !== []) {
            return [
                'ok' => false,
                'error' => ['code' => 'invalid_document', 'message' => __('Invalid document: fix the schema errors first.', 'nucleo')],
                'errors' => array_map(static fn ($e): array => $e->toArray(), $errors),
            ];
        }

        // Una pagina senza postId si compone con i layout di una pagina generica del sito, come in doc-validate.
        $page = $postId === null && !in_array($doc['kind'] ?? null, ['layout', 'section', 'popup'], true)
            ? Services::composer()->composeForNewPage($key, $doc, DocValidate::postType($input))
            : Services::composer()->composeForPost(is_int($postId) ? $postId : 0, $key, $doc);
        $report = DocumentService::scopeReport($doc, DocumentService::withSections(Services::quality()->check($page, $acknowledge)->withoutLayoutIssues(), $expansion, $key));
        // Title, description e slug valgono per le pagine: layout, sezioni e popup non hanno URL propri.
        $isPage = !in_array($doc['kind'] ?? null, ['layout', 'section', 'popup'], true);
        if ($postId !== null && $isPage) {
            $report = new QualityReport(
                array_merge($report->issues, PostChecks::check($postId, $page)),
                $report->acknowledged,
            );
        }

        return ['ok' => true] + $report->toArray();
    }

    public function permitted(array $input): bool
    {
        if (isset($input['postId'])) {
            return is_int($input['postId']) && current_user_can('edit_post', $input['postId']);
        }
        if (isset($input['url']) && is_string($input['url'])) {
            $postId = url_to_postid($input['url']);

            return $postId > 0 ? current_user_can('edit_post', $postId) : current_user_can('edit_posts');
        }

        return current_user_can('edit_posts');
    }

    /**
     * Tiene solo i riconoscimenti con rule, node e reason stringhe non vuote.
     * Gli altri vengono scartati: la issue resta aperta e bloccante.
     * @return list<array{rule:string,node:string,reason:string}>
     */
    private static function normalizeAcks(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $acks = [];
        foreach ($raw as $ack) {
            if (!is_array($ack)) {
                continue;
            }
            $rule = $ack['rule'] ?? null;
            $node = $ack['node'] ?? null;
            $reason = $ack['reason'] ?? null;
            if (is_string($rule) && $rule !== '' && is_string($node) && $node !== '' && is_string($reason) && $reason !== '') {
                $acks[] = ['rule' => $rule, 'node' => $node, 'reason' => $reason];
            }
        }

        return $acks;
    }
}
