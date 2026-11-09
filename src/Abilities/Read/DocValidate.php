<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Schema\ValidationError;
use Nucleo\Services;

/** nucleo/doc-validate: errori di schema e report qualità, senza scrivere. */
final class DocValidate implements AbilityDefinition
{
    /** URL segnaposto di media:<file> nel controllo: il file vero lo risolve il manifest al push. */
    private const MEDIA_PLACEHOLDER = 'https://placeholder.invalid/media/';

    public function name(): string
    {
        return 'nucleo/doc-validate';
    }

    public function args(): array
    {
        return [
            'label' => __('Validate document', 'nucleo'),
            'description' => 'Validates a document without saving it: schema errors and, if the document is valid, a quality report. A page or post is checked inside the site layouts it will receive (header, footer, single/product layout of postType), as doc-create does: a duplicate h1 in the header counts.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['document'],
                'properties' => [
                    'document' => ['type' => 'object', 'description' => 'Complete Nucleo document (schema, root).'],
                    'postType' => ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,20}$', 'description' => 'Post type the new content will have (default page). A content without postId is checked inside the layouts of that type, as doc-create and site-import do: post gets the single layout, product the product layout.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok', 'errors'],
                'properties' => [
                    'ok' => ['type' => 'boolean', 'description' => 'true if the document is valid.'],
                    'errors' => [
                        'type' => 'array',
                        'items' => ['type' => 'object', 'description' => 'Error with JSON path, value, allowed values and fix.'],
                    ],
                    'warnings' => [
                        'type' => 'array',
                        'description' => 'Present only if the document has references resolved at push time (media:<file>).',
                        'items' => ['type' => 'object', 'description' => 'Warning with JSON path and message.'],
                    ],
                    'quality' => [
                        'type' => 'object',
                        'description' => 'Present only if the document is valid.',
                        'properties' => [
                            'score' => ['type' => 'object'],
                            'blocking' => ['type' => 'boolean'],
                            'issues' => ['type' => 'array', 'items' => ['type' => 'object']],
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
        $document = $input['document'] ?? null;
        if (!is_array($document)) {
            return new \WP_Error('invalid_input', __('The "document" field must be an object.', 'nucleo'));
        }

        // media:<file> e menu:<slug> si risolvono come nel salvataggio, ma solo per il controllo.
        $refErrors = [];
        $warnings = [];
        if (is_array($document['root'] ?? null)) {
            $document['root'] = self::resolveRefs($document['root'], '/root', $refErrors, $warnings);
        }

        $expansion = Services::documents()->prepare($document);
        $doc = $expansion->doc;
        // Le condizioni dei layout si controllano qui sempre: il salvataggio blocca solo quelle nuove o cambiate.
        $errors = array_merge(
            $refErrors,
            \Nucleo\Layout\LayoutConditions::errorsOf(\Nucleo\Layout\LayoutConditions::normalize($document)),
            Services::documents()->check($expansion),
        );

        $result = [
            'ok' => $errors === [],
            'errors' => array_map(static fn ($e): array => $e->toArray(), $errors),
        ];
        if ($warnings !== []) {
            $result['warnings'] = $warnings;
        }
        if ($errors === []) {
            // Una pagina si controlla con i layout del sito (header, footer), come al salvataggio: i loro landmark contano.
            $composed = in_array($doc['kind'] ?? null, ['layout', 'section', 'popup'], true)
                ? Services::composer()->compose(['page:0' => $doc])
                : Services::composer()->composeForNewPage('page:0', $doc, self::postType($input));
            $report = \Nucleo\Compiler\DocumentService::scopeReport(
                $doc,
                \Nucleo\Compiler\DocumentService::withSections(Services::quality()->check($composed)->withoutLayoutIssues(), $expansion, 'page:0'),
            );
            $result += $report->toArray();
        }

        return $result;
    }

    /**
     * media:<file> in content.src diventa un URL segnaposto con un warning; menu:<slug> in settings.menu diventa l'ID
     * del menu, o un errore se il menu non esiste (la chiave si toglie, così non si segnala anche lo schema).
     * @param array<string,mixed> $node
     * @param list<ValidationError> $errors
     * @param list<array{path:string,message:string}> $warnings
     * @return array<string,mixed>
     */
    private static function resolveRefs(array $node, string $path, array &$errors, array &$warnings): array
    {
        $content = $node['content'] ?? null;
        if (is_array($content) && is_string($content['src'] ?? null) && str_starts_with($content['src'], 'media:')) {
            $ref = $content['src'];
            $warnings[] = ['path' => "$path/content/src", 'message' => sprintf(/* translators: %s: media file name, e.g. logo.png */ __('media:%s is resolved from the media manifest at push', 'nucleo'), substr($ref, 6))];
            $node['content']['src'] = self::MEDIA_PLACEHOLDER . rawurlencode(substr($ref, 6));
        }

        $settings = $node['settings'] ?? null;
        if (($node['type'] ?? null) === 'nc/menu' && is_array($settings) && is_string($settings['menu'] ?? null) && str_starts_with($settings['menu'], 'menu:')) {
            $slug = substr($settings['menu'], 5);
            $menu = wp_get_nav_menu_object($slug);
            if ($menu instanceof \WP_Term) {
                $node['settings']['menu'] = (int) $menu->term_id;
            } else {
                $errors[] = new ValidationError(
                    "$path/settings/menu",
                    sprintf(/* translators: %s: menu slug */ __('Menu not found: %s', 'nucleo'), $slug),
                    $settings['menu'],
                    [],
                    __('create the menu or use the slug of an existing one', 'nucleo'),
                );
                unset($node['settings']['menu']);
            }
        }

        if (is_array($node['children'] ?? null)) {
            foreach ($node['children'] as $i => $child) {
                if (is_array($child)) {
                    $node['children'][$i] = self::resolveRefs($child, "$path/children/$i", $errors, $warnings);
                }
            }
        }

        return $node;
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_posts');
    }

    /** @param array<string,mixed> $input */
    public static function postType(array $input): string
    {
        $type = $input['postType'] ?? null;

        return is_string($type) && preg_match('/^[a-z0-9_-]{1,20}$/', $type) === 1 ? $type : 'page';
    }
}
