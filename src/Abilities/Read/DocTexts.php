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
     * Percorsi dei campi traducibili di un nodo con i relativi testi: content.campo o settings.campo,
     * con indici e sotto-campi per le liste (es. settings.fields.0.options.1.label).
     * Un campo marcato translatable rende traducibili tutti i testi che contiene. Nelle liste in formato JSON Schema
     * (items.properties) si marcano anche i singoli sotto-campi.
     * @param array<string,mixed> $content
     * @param array<string,mixed> $settings
     * @return array<string,string> percorso → testo
     */
    public static function fieldPaths(Manifest $manifest, array $content, array $settings = []): array
    {
        $paths = [];
        foreach (['content' => [$manifest->content, $content], 'settings' => [$manifest->settings, $settings]] as $bucket => [$schemas, $values]) {
            self::walkProps($schemas, $values, $bucket, false, $paths);
        }

        return $paths;
    }

    /**
     * @param array<mixed> $schemas nome → schema del campo
     * @param array<mixed> $values
     * @param array<string,string> $paths
     */
    private static function walkProps(array $schemas, array $values, string $prefix, bool $inherited, array &$paths): void
    {
        foreach ($schemas as $key => $schema) {
            if (is_array($schema) && array_key_exists($key, $values)) {
                self::walkField($schema, $values[$key], "$prefix.$key", $inherited, $paths);
            }
        }
    }

    /**
     * @param array<mixed> $schema
     * @param array<string,string> $paths
     */
    private static function walkField(array $schema, mixed $value, string $path, bool $inherited, array &$paths): void
    {
        $translatable = $inherited || ($schema['translatable'] ?? false) === true;
        if (is_string($value)) {
            if ($translatable) {
                $paths[$path] = $value;
            }

            return;
        }
        if (!is_array($value)) {
            return;
        }
        // Formato Nucleo: item è la mappa dei sotto-campi. Formato JSON Schema: items è lo schema dell'elemento.
        $item = is_array($schema['item'] ?? null) ? ['properties' => $schema['item']] : ($schema['items'] ?? null);
        if (($schema['type'] ?? null) === 'array' && is_array($item)) {
            foreach ($value as $index => $element) {
                self::walkField($item, $element, "$path.$index", $translatable, $paths);
            }

            return;
        }
        if (is_array($schema['properties'] ?? null)) {
            self::walkProps($schema['properties'], $value, $path, $translatable, $paths);
        }
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
            $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
            foreach (self::fieldPaths($manifests[$type], $content, $settings) as $path => $text) {
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
