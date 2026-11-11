<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Blocks\Manifest;
use Nucleo\Services;

/** nucleo/doc-texts: testi traducibili di un documento, con il percorso del campo per riscriverli con nucleo/doc-texts-set. */
final class DocTexts implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/doc-texts';
    }

    public function args(): array
    {
        return [
            'label' => __('Read document texts', 'nucleo'),
            'description' => 'All translatable texts of a document, as node id, field path and text, without structure. Also returns the revision to pass to nucleo/doc-texts-set.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['postId'],
                'properties' => [
                    'postId' => ['type' => 'integer', 'minimum' => 1],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'revision' => ['type' => 'integer', 'minimum' => 0],
                    'texts' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'path', 'text'],
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'path' => ['type' => 'string'],
                                'text' => ['type' => 'string'],
                            ],
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
        if (!is_int($postId) || $postId < 1) {
            return new \WP_Error('invalid_input', __('Invalid postId field.', 'nucleo'));
        }

        $found = Services::documents()->get($postId);
        if ($found === null) {
            /* translators: %d: post ID of the requested document. */
            return ['ok' => false, 'error' => ['code' => 'not_found', 'message' => sprintf(__('Document not found: %d.', 'nucleo'), $postId)]];
        }

        return [
            'ok' => true,
            'revision' => $found['revision'],
            'texts' => self::collect(is_array($found['document']) ? $found['document'] : []),
        ];
    }

    public function permitted(array $input): bool
    {
        $postId = $input['postId'] ?? 0;

        return is_int($postId) && $postId > 0 && current_user_can('edit_post', $postId);
    }

    /**
     * Campi traducibili di tutto il documento, in ordine di albero.
     * @param array<string,mixed> $doc
     * @return list<array{id:string,path:string,text:string}>
     */
    public static function collect(array $doc): array
    {
        $out = [];
        $root = $doc['root'] ?? null;
        if (is_array($root)) {
            self::walk($root, Services::registry()->all(), $out);
        }

        return $out;
    }

    /**
     * Percorsi dei campi traducibili di un nodo con i relativi testi: content.campo, oppure content.campo.indice.sotto-campo per le liste.
     * @param array<string,mixed> $content
     * @return array<string,string> percorso → testo
     */
    public static function fieldPaths(Manifest $manifest, array $content): array
    {
        $paths = [];
        foreach ($manifest->content as $key => $schema) {
            if (!is_array($schema) || ($schema['translatable'] ?? false) !== true) {
                continue;
            }
            $value = $content[$key] ?? null;
            if (($schema['type'] ?? null) === 'array' && is_array($schema['item'] ?? null)) {
                if (!is_array($value)) {
                    continue;
                }
                foreach ($value as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    foreach (array_keys($schema['item']) as $sub) {
                        if (is_string($item[$sub] ?? null)) {
                            $paths["content.$key.$index.$sub"] = $item[$sub];
                        }
                    }
                }
            } elseif (is_string($value)) {
                $paths["content.$key"] = $value;
            }
        }

        return $paths;
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,Manifest> $manifests
     * @param list<array{id:string,path:string,text:string}> $out
     */
    private static function walk(array $node, array $manifests, array &$out): void
    {
        $id = $node['id'] ?? null;
        $type = $node['type'] ?? null;
        if (is_string($id) && is_string($type) && isset($manifests[$type])) {
            $content = is_array($node['content'] ?? null) ? $node['content'] : [];
            foreach (self::fieldPaths($manifests[$type], $content) as $path => $text) {
                $out[] = ['id' => $id, 'path' => $path, 'text' => $text];
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                self::walk($child, $manifests, $out);
            }
        }
    }
}
