<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

defined('ABSPATH') || exit;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Docs\Reference;
use Nucleo\Services;

/** nucleo/docs-search: cerca per termini negli argomenti di riferimento e nei manifest dei blocchi. Non scrive. */
final class DocsSearch implements AbilityDefinition
{
    private const EXCERPT = 200;

    public function name(): string
    {
        return 'nucleo/docs-search';
    }

    public function args(): array
    {
        return [
            'label' => __('Search docs', 'nucleo'),
            'description' => 'Searches the reference topics (nucleo/catalog-get with topic) and the block manifests by terms, and returns the best matches ranked by term matches: topic or block, title and excerpt. Use it when the topic or block name is unknown, then read the match with nucleo/catalog-get. What to do next on this site is in nucleo/guide.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['query'],
                'properties' => [
                    'query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100, 'description' => 'Terms to search, for example "contrast token".'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 5],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'results' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'topic' => ['type' => 'string'],
                                'block' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'excerpt' => ['type' => 'string'],
                            ],
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
        $query = $input['query'] ?? null;
        $limit = $input['limit'] ?? 5;
        if (!is_string($query) || mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            return new \WP_Error('invalid_input', __('The "query" field must be a text of 2 to 100 characters.', 'nucleo'));
        }
        if (!is_int($limit) || $limit < 1 || $limit > 20) {
            return new \WP_Error('invalid_input', __('The "limit" field must be an integer from 1 to 20.', 'nucleo'));
        }

        $terms = self::terms($query);
        if ($terms === []) {
            return ['ok' => true, 'results' => []];
        }

        $hits = [];
        foreach (Reference::TOPICS as $topic) {
            $doc = Reference::topic($topic);
            if ($doc === null) {
                continue;
            }
            $rules = is_array($doc['rules'] ?? null) ? array_filter($doc['rules'], 'is_string') : [];
            $text = implode(' ', array_merge([(string) ($doc['summary'] ?? '')], $rules));
            $score = self::score($topic . ' ' . $text, $terms);
            if ($score > 0) {
                $hits[] = [$score, ['topic' => $topic, 'title' => $topic, 'excerpt' => self::excerpt($text)]];
            }
        }

        foreach (Services::registry()->all() as $manifest) {
            $text = $manifest->title . ' ' . $manifest->description . ' ' . $manifest->aiHints;
            $score = self::score($manifest->name . ' ' . $text, $terms);
            if ($score > 0) {
                $hits[] = [$score, [
                    'block' => $manifest->name,
                    'title' => $manifest->title,
                    'excerpt' => self::excerpt($manifest->description !== '' ? $manifest->description : $manifest->aiHints),
                ]];
            }
        }

        usort($hits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return ['ok' => true, 'results' => array_map(static fn (array $hit): array => $hit[1], array_slice($hits, 0, $limit))];
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * Termini della ricerca: minuscoli, di almeno due caratteri, senza duplicati.
     * @return list<string>
     */
    private static function terms(string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($parts, static fn (string $term): bool => mb_strlen($term) >= 2)));
    }

    /** @param list<string> $terms */
    private static function score(string $text, array $terms): int
    {
        $haystack = mb_strtolower($text);
        $score = 0;
        foreach ($terms as $term) {
            $score += substr_count($haystack, $term);
        }

        return $score;
    }

    private static function excerpt(string $text): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($clean) > self::EXCERPT ? mb_substr($clean, 0, self::EXCERPT) . '...' : $clean;
    }
}
