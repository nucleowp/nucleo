<?php

declare(strict_types=1);

namespace Nucleo\Guide;

use Nucleo\Services;
use Nucleo\Tokens\TokenMatcher;

/**
 * Risposta di nucleo/guide: fase del sito, regole, cose da fare ed uscita della fase.
 */
final class Guide
{
    private const DESIGN_TOKEN = '<design.html>';
    /** Gruppi di blocchi ripetuti spiegati nel to-do, e copie mostrate per gruppo. */
    private const GROUPS_MAX = 3;
    private const GROUP_NODES_MAX = 4;

    /** @return array<string, mixed> */
    public static function run(?int $phase, string $design): array
    {
        $design = trim($design);
        $state = SiteState::collect();
        $scan = (new DocScan(new TokenMatcher(Services::tokens()), self::settingDefaults()))->scan($state->docs);

        $answer = $phase ?? self::currentPhase($state, $scan);
        $info = GuideRules::phase($answer);

        $todo = array_map(
            static fn (string $text): string => self::fill($text, $design),
            self::todo($answer, $state, $scan),
        );
        $cache = \Nucleo\Abilities\Read\SiteDescribe::cacheNotice();
        if ($cache !== null) {
            array_unshift($todo, $cache);
        }
        if ($state->coverage['truncated']) {
            $todo[] = sprintf(
                /* translators: 1: documents read, 2: total documents. */
                __('The checks read %1$d of %2$d documents: layouts and sections first, then the most recently modified.', 'nucleo'),
                $state->coverage['read'],
                $state->coverage['total'],
            );
        }

        return [
            'ok' => true,
            'phase' => $answer,
            'title' => $info['title'],
            'state' => [
                'tokens' => $state->tokens,
                'sections' => $state->sections,
                'layouts' => $state->layouts,
                'menus' => $state->menus,
                'postTypes' => $state->postTypes,
                'documents' => $state->documents,
                'checks' => self::checks($scan),
                // Blocchi dichiarati diversi di proposito, con il motivo: chi controlla il lavoro li vede qui.
                'distinct' => $scan->samples['distinct'] ?? [],
                'coverage' => $state->coverage,
            ],
            // La fase 0 resta sempre presente in forma breve: la guida non scrive nulla e non ricorda le chiamate.
            'basics' => $answer === 0 ? [] : self::basics(),
            'todo' => array_values($todo),
            'rules' => array_map(
                static fn (array $rule): array => [
                    'code' => $rule['code'],
                    'text' => self::fill($rule['text'], $design),
                    'tools' => $rule['tools'],
                ],
                $info['rules'],
            ),
            'exit' => $info['exit'],
            'next' => $answer < GuideRules::LAST ? 'nucleo guide ' . ($answer + 1) : null,
        ];
    }

    /**
     * Default delle impostazioni di ogni blocco, dai manifest.
     * @return array<string, array<string, mixed>>
     */
    private static function settingDefaults(): array
    {
        $out = [];
        foreach (Services::registry()->all() as $name => $manifest) {
            foreach ($manifest->settings as $key => $decl) {
                if (is_array($decl) && array_key_exists('default', $decl)) {
                    $out[(string) $name][(string) $key] = $decl['default'];
                }
            }
        }

        return $out;
    }

    /** Senza fase richiesta: la prima fase non soddisfatta tra 1 e 4; tutte soddisfatte → 4. */
    private static function currentPhase(SiteState $state, ScanResult $scan): int
    {
        for ($n = 1; $n <= GuideRules::LAST; $n++) {
            if (!self::satisfied($n, $state, $scan)) {
                return $n;
            }
        }

        return GuideRules::LAST;
    }

    private static function satisfied(int $phase, SiteState $state, ScanResult $scan): bool
    {
        return match ($phase) {
            1 => array_sum($state->tokens) > 0 && $scan->tokenLiterals === 0 && $scan->repeated === 0,
            2 => $state->headerSite && $state->footerSite && $state->menus > 0 && $scan->globalParts === 0,
            3 => $scan->handLists === 0 && self::typesReady($state),
            default => $scan->inertButtons === 0,
        };
    }

    /** Ogni tipo del modello ha un layout single e almeno un elemento pubblicato. */
    private static function typesReady(SiteState $state): bool
    {
        foreach ($state->postTypes as $type) {
            if (empty($state->singleLayout[$type]) || ($state->published[$type] ?? 0) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Regole della fase 0 in forma breve: codice e prima frase.
     * @return list<array{code:string,text:string}>
     */
    private static function basics(): array
    {
        return array_map(
            static fn (array $rule): array => [
                'code' => $rule['code'],
                'text' => preg_match('/^.+?\.(?=\s|$)/u', $rule['text'], $m) === 1 ? $m[0] : $rule['text'],
            ],
            GuideRules::phase(0)['rules'],
        );
    }

    /** @return list<string> */
    private static function todo(int $phase, SiteState $state, ScanResult $scan): array
    {
        return match ($phase) {
            0 => [],
            1 => self::todoPhase1($state, $scan),
            2 => self::todoPhase2($state, $scan),
            3 => self::todoPhase3($state, $scan),
            default => self::todoPhase4($scan),
        };
    }

    /** @return list<string> */
    private static function todoPhase1(SiteState $state, ScanResult $scan): array
    {
        $todo = [];
        if (array_sum($state->tokens) === 0) {
            $todo[] = __('Run nucleo tokens suggest <design.html>, then tokens-set.', 'nucleo');
        }
        if ($scan->tokenLiterals > 0) {
            $todo[] = self::withSamples(
                __('Replace the literal values with tokens: use $name, or run push --use-tokens', 'nucleo'),
                $scan->tokenLiterals,
                $scan->samples['tokenLiterals'] ?? [],
            );
        }
        if ($scan->repeated > 0) {
            $todo[] = self::withSamples(
                __('Turn each repeated block into a section with parameters, inserted with nc/section-ref', 'nucleo'),
                $scan->repeated,
                $scan->samples['repeated'] ?? [],
            );
            foreach (array_slice($scan->groups, 0, self::GROUPS_MAX) as $group) {
                $todo[] = self::sameBlocks($group);
            }
            $todo[] = __('To replace a copy, keep its id: doc-patch with {"op": "remove", "id": "<id>"}, then {"op": "insert", "parent": "<parent id>", "index": <position>, "node": {"id": "<id>", "type": "nc/section-ref", "settings": {"section": "<slug>", "params": {...}}}}. If the copy is the root of its document (the root cannot be removed), keep the root: remove its children and insert one nc/section-ref whose section holds them. Or edit the file and push. A block that differs on purpose: add "guide": {"distinct": "<reason>"} to the node and it no longer counts.', 'nucleo');
        }
        if ($scan->mixedSections > 0) {
            $todo[] = self::withSamples(
                __('R1.7: these sections mix sections and other blocks among their direct children, so the other blocks reach the page edges. Put the other blocks in a section of their own', 'nucleo'),
                $scan->mixedSections,
                $scan->samples['mixedSections'] ?? [],
            );
        }

        return $todo;
    }

    /**
     * Perché la guida considera uguali le copie di un blocco ripetuto.
     * @param array{nodes:list<string>, size:int, outline:string} $group
     */
    private static function sameBlocks(array $group): string
    {
        $nodes = $group['nodes'];
        $shown = array_slice($nodes, 0, self::GROUP_NODES_MAX);
        $more = count($nodes) - count($shown);

        return sprintf(
            /* translators: 1: node positions joined by " = ", 2: other copies, 3: number of nodes, 4: list of block types. */
            __('Same block: %1$s%2$s. Same tree of %3$d nodes (%4$s), with the same heading levels, layouts and inserted sections. Texts, images, links and styles may differ: they become parameters.', 'nucleo'),
            implode(' = ', $shown),
            $more > 0 ? sprintf(/* translators: %d: number of other copies. */ __(' (+%d more)', 'nucleo'), $more) : '',
            $group['size'],
            $group['outline'],
        );
    }

    /** @return list<string> */
    private static function todoPhase2(SiteState $state, ScanResult $scan): array
    {
        $todo = [];
        foreach (['header', 'footer'] as $area) {
            $present = ($state->layouts[$area] ?? 0) > 0;
            $whole = $area === 'header' ? $state->headerSite : $state->footerSite;
            if (!$present) {
                $todo[] = sprintf(
                    /* translators: %s: layout area, header or footer. */
                    __('No %s layout yet: create one with that area and the condition entire_site.', 'nucleo'),
                    $area,
                );
            } elseif (!$whole) {
                $todo[] = sprintf(
                    /* translators: %s: layout area, header or footer. */
                    __('The %s layout exists, but no layout applies it to the entire site: add the condition entire_site.', 'nucleo'),
                    $area,
                );
            }
        }
        if ($state->menus === 0) {
            $todo[] = __('Create the navigation with menu-set and nc/menu.', 'nucleo');
        }
        if ($scan->globalParts > 0) {
            $todo[] = self::withSamples(
                __('Move the header, footer and menus out of the pages and into the layouts', 'nucleo'),
                $scan->globalParts,
                $scan->samples['globalParts'] ?? [],
            );
        }
        if ($scan->pathLinks > 0) {
            $todo[] = self::withSamples(
                __('Replace the paths with references: page:<slug>, <post-type>:<slug>', 'nucleo'),
                $scan->pathLinks,
                $scan->samples['pathLinks'] ?? [],
            );
        }

        return $todo;
    }

    /** @return list<string> */
    private static function todoPhase3(SiteState $state, ScanResult $scan): array
    {
        $todo = [];
        if ($scan->handLists > 0) {
            $todo[] = self::withSamples(
                __('Turn the repeated elements into content: model-set, then nc/query or nc/collection', 'nucleo'),
                $scan->handLists,
                $scan->samples['handLists'] ?? [],
            );
        }
        foreach ($state->postTypes as $type) {
            if (empty($state->singleLayout[$type])) {
                /* translators: %s: post type slug. */
                $todo[] = sprintf(__('Post type %s has no single layout: create a layout with area single and condition post_type.', 'nucleo'), $type);
            }
            if (($state->published[$type] ?? 0) === 0) {
                /* translators: %s: post type slug. */
                $todo[] = sprintf(__('Post type %s has no published items: add them with content-upsert or content/<type>/<slug>.json.', 'nucleo'), $type);
            }
        }

        return $todo;
    }

    /** @return list<string> */
    private static function todoPhase4(ScanResult $scan): array
    {
        $todo = [];
        if ($scan->inertButtons > 0) {
            $todo[] = self::withSamples(
                __('Give each button a link or an action: see content.button.inert', 'nucleo'),
                $scan->inertButtons,
                $scan->samples['inertButtons'] ?? [],
            );
        }
        if ($scan->forms > 0) {
            /* translators: %d: number of forms. */
            $todo[] = sprintf(__('Send a test submission to each form (%d).', 'nucleo'), $scan->forms);
        }

        return $todo;
    }

    /** @return array<string, int> */
    private static function checks(ScanResult $scan): array
    {
        return [
            'tokenLiterals' => $scan->tokenLiterals,
            'repeated' => $scan->repeated,
            'globalParts' => $scan->globalParts,
            'pathLinks' => $scan->pathLinks,
            'handLists' => $scan->handLists,
            'inertButtons' => $scan->inertButtons,
            'forms' => $scan->forms,
            'lists' => $scan->lists,
            'sectionRefs' => $scan->sectionRefs,
            'mixedSections' => $scan->mixedSections,
            'distinct' => $scan->distinct,
        ];
    }

    /** @param list<string> $samples */
    private static function withSamples(string $text, int $count, array $samples): string
    {
        return $samples === []
            ? sprintf('%s (%d).', $text, $count)
            : sprintf('%s (%d, e.g. %s).', $text, $count, implode(', ', $samples));
    }

    private static function fill(string $text, string $design): string
    {
        return $design === '' ? $text : str_replace(self::DESIGN_TOKEN, $design, $text);
    }
}
