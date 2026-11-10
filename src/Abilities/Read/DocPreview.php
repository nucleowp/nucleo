<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Abilities\Preview;
use Nucleo\Services;

/** nucleo/doc-preview: compila un documento (o uno salvato) senza salvare. Restituisce html e css. */
final class DocPreview implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/doc-preview';
    }

    public function args(): array
    {
        return [
            'label' => __('Document preview', 'nucleo'),
            'description' => 'Compiles a document without saving it and returns HTML and CSS. Accepts document or postId. With contextPostId the dynamic slots (woo/*, bindings) are filled as on the page of that post: use it to see a product layout on a real product. It does not check schema or quality: use nucleo/doc-validate before saving, nucleo/quality-check after.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'document' => ['type' => 'object', 'description' => 'Nucleo document to compile.'],
                    'postId' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Saved document to compile.'],
                    'contextPostId' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Post whose page fills the dynamic slots, for example a product id from content-list with type "product". Without it the slots stay empty.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'html' => ['type' => 'string'],
                    'css' => ['type' => 'string'],
                    'previewUrl' => ['type' => 'string', 'description' => 'Signed link, valid 30 minutes, to open the document in a browser (saved or passed as document).'],
                    'error' => ['type' => 'object'],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    public function execute(array $input): array|\WP_Error
    {
        $document = $input['document'] ?? null;
        $postId = $input['postId'] ?? null;
        if (($document === null) === ($postId === null)) {
            return new \WP_Error('invalid_input', __('Provide either document or postId.', 'nucleo'));
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
            $document = $found['document'];
        }

        if (!is_array($document)) {
            return new \WP_Error('invalid_input', __('The "document" field must be an object.', 'nucleo'));
        }
        $context = $input['contextPostId'] ?? 0;
        if (!is_int($context) || ($context !== 0 && get_post($context) === null)) {
            return new \WP_Error('invalid_input', __('contextPostId must be the id of an existing post.', 'nucleo'));
        }

        $result = Preview::render($document, false, is_int($postId) ? $postId : 0, $context);
        if (($result['ok'] ?? false) === true) {
            if (is_int($postId) && $context === 0) {
                $result['previewUrl'] = Preview::signedUrl(Preview::issue($postId, 1800), $postId);
            } else {
                // Senza post, o con gli slot riempiti su un altro post: html e css restano nel transient per 30 minuti.
                $token = Preview::issue(0, 1800);
                Preview::store($token, $result['html'], $result['css'], 1800);
                $result['previewUrl'] = Preview::signedUrl($token, 0);
            }
        }

        return $result;
    }

    public function permitted(array $input): bool
    {
        if (isset($input['contextPostId']) && (!is_int($input['contextPostId']) || !current_user_can('read_post', $input['contextPostId']))) {
            return false;
        }
        if (isset($input['postId'])) {
            return is_int($input['postId']) && current_user_can('edit_post', $input['postId']);
        }

        return current_user_can('edit_posts');
    }
}
