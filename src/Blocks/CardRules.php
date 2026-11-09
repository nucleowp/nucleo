<?php

declare(strict_types=1);

namespace Nucleo\Blocks;

use Nucleo\Schema\ValidationError;

/** Regole della scheda (nc/query, nc/collection): blocchi che dipendono dalla pagina o ripetono query non sono ammessi. */
final class CardRules
{
    public const NOT_ALLOWED = [
        'nc/query', 'nc/collection', 'nc/pagination', 'nc/form', 'nc/native-form', 'nc/search', 'nc/breadcrumbs',
        'nc/archive-title', 'nc/archive-description', 'nc/post-content', 'nc/menu', 'nc/mega-panel',
    ];

    /** Sorgenti di bind che cambiano per visitatore o richiesta: non ammesse nelle schede. */
    public const VISITOR_SOURCES = ['user', 'query'];

    /**
     * Errori card_block_not_allowed nel sottoalbero della scheda.
     * @param array<string,mixed> $node radice della scheda
     * @return list<ValidationError>
     */
    public static function errors(array $node, string $path): array
    {
        $errors = [];
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $when = $node['visibility']['when'] ?? null;
        if (in_array($type, self::NOT_ALLOWED, true) || str_starts_with($type, 'woo/')) {
            $errors[] = new ValidationError(
                "$path/type",
                sprintf(/* translators: %s: block type */ __('block not allowed in a card: %s', 'nucleo'), $type),
                $type,
                [],
                __('a card shows one item: remove lists, forms and page-level blocks from it', 'nucleo'),
                'card_block_not_allowed',
            );
        } elseif (self::visitorSource($node) !== null) {
            $errors[] = new ValidationError(
                "$path/bind",
                sprintf(/* translators: %s: bind source */ __('bind source not allowed in a card: %s', 'nucleo'), (string) self::visitorSource($node)),
                self::visitorSource($node),
                [],
                __('a card is cached and shared by every visitor: read the item (post, nucleo, item), not the visitor or the request', 'nucleo'),
                'card_block_not_allowed',
            );
        } elseif (self::hasVisitorCondition($when)) {
            $errors[] = new ValidationError(
                "$path/visibility/when",
                __('visitor conditions (logged_in, logged_out, role:) are not allowed in a card', 'nucleo'),
                null,
                [],
                __('remove logged_in, logged_out and role: from the card nodes; filled and empty are allowed', 'nucleo'),
                'card_block_not_allowed',
            );
        }

        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $i => $child) {
            if (is_array($child)) {
                array_push($errors, ...self::errors($child, "$path/children/$i"));
            }
        }

        return $errors;
    }

    /**
     * Prima sorgente di bind che dipende dal visitatore o dalla richiesta (user, query): la scheda è in cache per tutti.
     * @param array<string,mixed> $node
     */
    private static function visitorSource(array $node): ?string
    {
        foreach (is_array($node['bind'] ?? null) ? $node['bind'] : [] as $bind) {
            $source = is_array($bind) ? ($bind['source'] ?? null) : null;
            if (is_string($source) && in_array($source, self::VISITOR_SOURCES, true)) {
                return $source;
            }
        }

        return null;
    }

    /** Condizioni del visitatore nella scheda: tutto ciò che non è filled o empty. */
    private static function hasVisitorCondition(mixed $when): bool
    {
        if (!is_array($when)) {
            return false;
        }
        foreach ($when as $condition) {
            $is = is_array($condition) ? ($condition['is'] ?? null) : null;
            if ($is !== 'filled' && $is !== 'empty') {
                return true;
            }
        }

        return false;
    }
}
