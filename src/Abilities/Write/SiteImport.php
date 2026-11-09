<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Write;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Site\SiteImporter;
use Nucleo\Storage\AuditLog;

/** nucleo/site-import: importa i file di un sito, crea o aggiorna per slug. */
final class SiteImport implements AbilityDefinition
{
    public function name(): string
    {
        return 'nucleo/site-import';
    }

    public function args(): array
    {
        return [
            'label' => __('Import site', 'nucleo'),
            'description' => 'Imports the files produced by nucleo/site-export: creates or updates tokens, sections, pages, posts, layouts, rules, media, menus and site settings (title, tagline, homepage, language). With dryRun true it validates every document like a real import (schema and blocking quality issues) without writing. With force true it also imports the posts, tokens and site settings modified on the site after the export; with force as a list of file paths (e.g. ["pages/home.json"], "site.json" for tokens and settings) only those files. acknowledge carries the blocking issues a human has accepted, per file. The report returns the new base (base, tokensBase) to write into site.json. Quality is checked on the page composed with the site layouts it will receive (header, footer, single or product), also for new pages. Pages and posts are imported before layouts, because layout conditions refer to them (page:<slug>): a page is checked against the layouts already on the site, not those of the same import. content/ and terms/ files are not handled here: they go to content-upsert and terms-set.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['files'],
                'properties' => [
                    'files' => [
                        'type' => 'object',
                        'description' => 'Relative path → JSON content, as in nucleo/site-export.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'dryRun' => ['type' => 'boolean', 'default' => false],
                    'force' => [
                        'type' => ['boolean', 'array'],
                        'items' => ['type' => 'string'],
                        'default' => false,
                        'description' => 'true for every file, or the file paths to overwrite even if they changed on the site (nucleo push --force <file>).',
                    ],
                    'pending' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'dryRun only: symbols of the published content items and terms that the same push creates first, e.g. "evento:congresso", "page:servizi/consulenza", "term:area/nutrizione". Menu items and site settings treat them as found. nucleo push --dry-run sends them.',
                    ],
                    'acknowledge' => [
                        'type' => 'object',
                        'description' => 'File path → blocking quality issues accepted by a human, as in nucleo/doc-publish. Ask the user first and quote the consent in reason.',
                        'additionalProperties' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['rule', 'node', 'reason'],
                                'properties' => [
                                    'rule' => ['type' => 'string', 'minLength' => 1],
                                    'node' => ['type' => 'string', 'minLength' => 1],
                                    'reason' => ['type' => 'string', 'minLength' => 1],
                                ],
                            ],
                        ],
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['created', 'updated', 'errors', 'ids'],
                'properties' => [
                    'created' => ['type' => 'integer', 'minimum' => 0],
                    'updated' => ['type' => 'integer', 'minimum' => 0],
                    'unchanged' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Documents equal to the site (document, title, status): not saved again.'],
                    'errors' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'file' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                                'code' => ['type' => 'string', 'enum' => SiteImporter::ERROR_CODES],
                                'guide' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'warnings' => [
                        'type' => 'array',
                        'description' => 'Files imported or skipped that need a look: a parent page not published, a post changed only on the site.',
                        'items' => ['type' => 'object', 'properties' => ['file' => ['type' => 'string'], 'message' => ['type' => 'string']]],
                    ],
                    'ids' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                    'base' => ['type' => 'object', 'description' => 'File path → fingerprint of the saved document: the new site.json base.', 'additionalProperties' => ['type' => 'string']],
                    'tokensBase' => ['type' => 'string', 'description' => 'Fingerprint of the site tokens after the import: the new site.json tokensBase.'],
                ],
            ],
            'meta' => [
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    public function execute(array $input): array|\WP_Error
    {
        $files = $input['files'] ?? null;
        $dryRun = $input['dryRun'] ?? false;
        $force = $input['force'] ?? false;
        $forceList = is_array($force) && array_is_list($force) && array_filter($force, static fn ($p): bool => !is_string($p)) === [];
        if (!is_array($files) || !is_bool($dryRun) || (!is_bool($force) && !$forceList)) {
            return new \WP_Error('invalid_input', __('Invalid files, dryRun or force fields.', 'nucleo'));
        }

        $acknowledge = self::acknowledgements($input['acknowledge'] ?? null);
        if ($acknowledge === null) {
            return new \WP_Error('invalid_input', __('Invalid acknowledge field: each file path needs a list of {rule, node, reason} with a reason.', 'nucleo'));
        }

        $clean = [];
        foreach ($files as $path => $content) {
            if (!is_string($path) || !is_string($content)) {
                return new \WP_Error('invalid_input', __('Each files entry must be a path with text content.', 'nucleo'));
            }
            $clean[$path] = $content;
        }

        $pending = $input['pending'] ?? [];
        if (!is_array($pending) || array_filter($pending, static fn ($s): bool => !is_string($s)) !== []) {
            return new \WP_Error('invalid_input', __('Invalid pending field: a list of symbols.', 'nucleo'));
        }

        $report = SiteImporter::import($clean, $dryRun, $force, $acknowledge, array_values($pending));
        AuditLog::record('site-import', 0, $report['errors'] === [], $dryRun ? 'dry-run' : 'import');

        return $report;
    }

    /** @return array<string,list<array{rule:string,node:string,reason:string}>>|null null se la forma non è valida */
    private static function acknowledgements(mixed $raw): ?array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            return null;
        }

        $out = [];
        foreach ($raw as $path => $items) {
            if (!is_string($path) || !is_array($items) || !array_is_list($items)) {
                return null;
            }
            foreach ($items as $item) {
                if (
                    !is_array($item) || !is_string($item['rule'] ?? null) || !is_string($item['node'] ?? null)
                    || !is_string($item['reason'] ?? null) || trim($item['reason']) === ''
                ) {
                    return null;
                }
                $out[$path][] = ['rule' => $item['rule'], 'node' => $item['node'], 'reason' => $item['reason']];
            }
        }

        return $out;
    }

    public function permitted(array $input): bool
    {
        return current_user_can('manage_options');
    }
}
