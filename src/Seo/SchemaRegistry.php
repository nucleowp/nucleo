<?php

declare(strict_types=1);

namespace Nucleo\Seo;

defined('ABSPATH') || exit;

use JsonException;
use Nucleo\Integrations\SeoBridge;

/** Consolida gli script JSON-LD dell'HTML compilato in un unico @graph, senza duplicati. */
final class SchemaRegistry
{
    private const PATTERN = '#<script\b[^>]*\btype\s*=\s*(["\'])application/ld\+json\1[^>]*>(.*?)</script\s*>#is';

    /** Rimuove gli script application/ld+json validi e ne aggiunge uno solo con @graph in fondo. */
    public static function consolidate(string $html): string
    {
        /** @var list<array<string,mixed>> $nodes */
        $nodes = [];
        $cleaned = preg_replace_callback(self::PATTERN, static function (array $match) use (&$nodes): string {
            $node = self::decode($match[2]);
            if ($node === null) {
                return $match[0];
            }
            $nodes[] = $node;

            return '';
        }, $html);

        if ($cleaned === null || $nodes === []) {
            return $cleaned ?? $html;
        }

        $graph = self::merge($nodes, self::skipTypes());
        if ($graph === []) {
            return $cleaned;
        }

        $json = wp_json_encode(
            ['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR,
        );

        return $cleaned . '<script type="application/ld+json">' . $json . '</script>';
    }

    /** @return array<string,mixed>|null */
    private static function decode(string $body): ?array
    {
        try {
            $value = json_decode(trim($body), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($value) && !array_is_list($value) ? $value : null;
    }

    /** @return list<string> */
    public static function skipTypes(): array
    {
        if (!function_exists('apply_filters')) {
            return [];
        }

        $default = SeoBridge::active() !== null
            ? ['WebPage', 'WebSite', 'Organization', 'BreadcrumbList']
            : [];
        $types = apply_filters('nucleo/schema/skip_types', $default);

        return is_array($types) ? array_values(array_filter($types, 'is_string')) : [];
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @param list<string> $skip
     * @return list<array<string,mixed>>
     */
    public static function merge(array $nodes, array $skip): array
    {
        $graph = [];
        $seen = [];
        $faqIndex = null;

        foreach ($nodes as $node) {
            unset($node['@context']);
            $type = $node['@type'] ?? null;
            if (is_string($type) && in_array($type, $skip, true)) {
                continue;
            }

            if ($type === 'FAQPage') {
                $entities = is_array($node['mainEntity'] ?? null) ? array_values($node['mainEntity']) : [];
                if ($faqIndex === null) {
                    // Le domande di ogni FAQ, anche la prima, entrano sotto con $entities.
                    $node['mainEntity'] = [];
                    $graph[] = $node;
                    $faqIndex = array_key_last($graph);
                }
                if ($entities !== []) {
                    $graph[$faqIndex]['mainEntity'] = [...($graph[$faqIndex]['mainEntity'] ?? []), ...$entities];
                }
                continue;
            }

            $key = (string) wp_json_encode($node);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $graph[] = $node;
        }

        return array_values($graph);
    }
}
