<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

defined('ABSPATH') || exit;

use InvalidArgumentException;
use Nucleo\Bindings\BindingSource;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\Registry;
use Nucleo\Blocks\RenderContext;
use Nucleo\Css\CssResult;
use Nucleo\Tokens\TokenResolver;

/** Context of one compilation: slots, LCP claim, block types in document order and renderer dispatch. */
final class CompileContext implements RenderContext, \Nucleo\Blocks\ColumnAware
{
    /** @var array<int,array{block:string,data:array<string,mixed>}> */
    private array $slots = [];

    /** Segnaposto dell'anteprima: stile in linea, l'iframe non carica gli stili dell'admin. */
    private const PLACEHOLDER_STYLE = 'padding:12px 16px;border:1px dashed rgba(13,25,18,.3);border-radius:8px;'
        . 'background:rgba(13,25,18,.03);color:rgba(13,25,18,.6);font:500 13px/18px system-ui,sans-serif;text-align:center';

    /** @var array<string,true> distinct block types, in document order */
    private array $blockTypes = [];

    private ?string $lcpNodeId = null;

    /** @var list<string> dipendenze risolte, senza duplicati, in ordine di comparsa */
    private array $deps = [];

    /** contatore dei token nc/bind, unico per compilazione */
    private int $bindSeq = 0;

    /** @var array<string,float> frazione della larghezza dello schermo per breakpoint, dalle colonne degli antenati */
    private array $fractions = [];

    public function __construct(
        private readonly Registry $registry,
        private readonly TokenResolver $tokens,
        private readonly CssResult $css,
        private readonly ?BindingSource $bindings = null,
        /** true solo per l'anteprima dell'editor: marca il primo tag di ogni nodo con data-nc-id */
        private readonly bool $markIds = false,
        /** @var array<string,string> anteprima dell'editor: nodi non validi (id => motivo) mostrati come segnaposto */
        private readonly array $placeholders = [],
        /** @var array<string,int> larghezza minima di ogni breakpoint, come nel CSS */
        private readonly array $minWidths = \Nucleo\Css\Breakpoints::DEFAULTS,
    ) {
    }

    /**
     * Frazioni per breakpoint dei figli di un nodo con style.columns numerico: quelle correnti divise per il numero
     * di colonne. Un valore non numerico ("1fr 2fr") lascia le frazioni come sono.
     * @param array<string,float> $current
     * @param array<string,int> $minWidths
     * @return array<string,float>
     */
    public static function childFractions(array $current, mixed $columns, array $minWidths): array
    {
        $byBreakpoint = is_array($columns) ? $columns : ['base' => $columns];
        $out = [];
        $count = null;
        foreach (array_keys($minWidths) as $bp) {
            if (array_key_exists($bp, $byBreakpoint)) {
                $value = $byBreakpoint[$bp];
                $count = is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1) ? max(1, (int) $value) : null;
            }
            $fraction = $current[$bp] ?? 1.0;
            $out[$bp] = $count === null ? $fraction : $fraction / $count;
        }

        return $out;
    }

    /**
     * sizes dalle frazioni: dal breakpoint più largo al più stretto, "(min-width: Npx) Xvw", poi la base.
     * Con la larghezza del file: min(Xvw, Wpx). Null se tutte le frazioni valgono 1.
     * @param array<string,float> $fractions
     * @param array<string,int> $minWidths
     */
    public static function sizesFor(array $fractions, array $minWidths, ?int $maxWidth): ?string
    {
        if ($fractions === [] || min($fractions) >= 1.0) {
            return null;
        }
        $value = static function (float $fraction) use ($maxWidth): string {
            $vw = rtrim(rtrim(number_format($fraction * 100, 2, '.', ''), '0'), '.') . 'vw';

            return $maxWidth !== null && $maxWidth > 0 ? 'min(' . $vw . ', ' . $maxWidth . 'px)' : $vw;
        };
        $parts = [];
        foreach (array_reverse($minWidths, true) as $bp => $min) {
            $fraction = $fractions[$bp] ?? 1.0;
            if ($min <= 0) {
                $parts[] = $value($fraction);
                break;
            }
            // Un breakpoint con lo stesso valore del più stretto si unisce a quello.
            if ($fraction !== ($fractions[self::narrower($minWidths, (string) $bp)] ?? 1.0)) {
                $parts[] = '(min-width: ' . $min . 'px) ' . $value($fraction);
            }
        }

        return implode(', ', $parts);
    }

    /** @param array<string,int> $minWidths */
    private static function narrower(array $minWidths, string $bp): string
    {
        $keys = array_keys($minWidths);
        $i = array_search($bp, $keys, true);

        return is_int($i) && $i > 0 ? $keys[$i - 1] : $bp;
    }

    public function columnSizes(?int $maxWidth): ?string
    {
        return self::sizesFor($this->fractions, $this->minWidths, $maxWidth);
    }

    /** Renders one node with the renderer of its type. A node with ref renders as an empty string. */
    public function renderNode(Node $node): string
    {
        if ($node->ref !== null) {
            return '';
        }
        if (isset($this->placeholders[$node->id])) {
            return $this->placeholder($node, $this->placeholders[$node->id]);
        }
        if (!$this->registry->has($node->type)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: block type */ __('Block type not registered: %s', 'nucleo'), $node->type));
        }

        $this->blockTypes[$node->type] = true;
        $this->collectDeps($node);

        [$node, $pending] = $this->applyBindings($node);
        // I figli di un nodo con colonne occupano una parte della larghezza: nc/image ne ricava sizes.
        $outer = $this->fractions;
        if (isset($node->style['columns'])) {
            $this->fractions = self::childFractions($outer, $node->style['columns'], $this->minWidths);
        }
        try {
            $html = $this->registry->renderer($node->type)->render($node, $this);
        } finally {
            $this->fractions = $outer;
        }
        // Link con un riferimento senza contenuto: "#" e aria-disabled, in qualunque blocco.
        if (str_contains($html, LinkRefs::UNRESOLVED)) {
            $html = str_replace(
                ['href="' . LinkRefs::UNRESOLVED . '"', "href='" . LinkRefs::UNRESOLVED . "'"],
                'href="#" aria-disabled="true"',
                $html,
            );
        }
        if ($this->markIds) {
            $html = $this->markNode($node, $html);
        }
        if ($pending !== []) {
            $html = $this->slot('nc/bind', ['html' => $html, 'binds' => $pending], '');
        }

        $when = $node->visibility['when'] ?? null;
        if (is_array($when) && $when !== []) {
            return $this->slot('nc/visibility', ['when' => $when, 'html' => $html], '');
        }

        return $html;
    }

    /**
     * Aggiunge data-nc-id al primo tag di apertura dell'HTML del nodo.
     * Nodo senza tag (blocco dinamico riempito solo sul sito, o vuoto finché non è configurato):
     * nell'anteprima diventa un segnaposto con il titolo del blocco, visibile e selezionabile.
     */
    private function markNode(Node $node, string $html): string
    {
        $attr = ' data-nc-id="' . htmlspecialchars($node->id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        $visible = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        if (!preg_match('/<[a-zA-Z]/', $visible)) {
            return $this->placeholder($node, '');
        }

        return preg_replace_callback(
            '/<([a-zA-Z][a-zA-Z0-9-]*)/',
            static fn (array $m): string => '<' . $m[1] . $attr,
            $html,
            1,
        ) ?? $html;
    }

    /** Segnaposto dell'anteprima: titolo del blocco e, se c'è, il motivo (es. campo obbligatorio mancante). */
    private function placeholder(Node $node, string $note): string
    {
        $title = $this->registry->get($node->type)->title ?? $node->type;
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div class="nc-editor-placeholder" data-nc-id="' . $esc($node->id) . '" style="' . self::PLACEHOLDER_STYLE . '">'
            . '<strong>' . $esc($title) . '</strong>' . ($note !== '' ? '<br>' . $esc($note) : '') . '</div>';
    }

    /** Risolve i deps del manifest del nodo; un modello con valore mancante o non scalare viene saltato. */
    private function collectDeps(Node $node): void
    {
        $manifest = $this->registry->get($node->type);
        if ($manifest === null) {
            return;
        }

        foreach ($manifest->deps as $template) {
            $dep = $this->resolveDep($node, $template);
            if ($dep !== null && !in_array($dep, $this->deps, true)) {
                $this->deps[] = $dep;
            }
        }
    }

    private function resolveDep(Node $node, string $template): ?string
    {
        $missing = false;
        $resolved = preg_replace_callback(
            '/\{(settings|content)\.([A-Za-z0-9_-]+)\}/',
            function (array $m) use ($node, &$missing): string {
                $bucket = $m[1] === 'settings' ? $node->settings : $node->content;
                $value = $bucket[$m[2]] ?? null;
                if (!is_string($value) && !is_int($value) && !is_float($value) || $value === '') {
                    $missing = true;

                    return '';
                }

                return (string) $value;
            },
            $template,
        );

        return $missing || $resolved === null || $resolved === '' ? null : $resolved;
    }

    /**
     * Applica i bind del nodo: i valori statici entrano nel nodo, i dinamici diventano token da sostituire a runtime.
     * @return array{0:Node,1:list<array{token:string,source:string,field:string,fallback:string,escape:string}>}
     */
    private function applyBindings(Node $node): array
    {
        if ($node->bind === []) {
            return [$node, []];
        }

        $content = $node->content;
        $settings = $node->settings;
        $pending = [];

        foreach ($node->bind as $path => $spec) {
            if (
                !is_string($path) || !is_array($spec)
                || preg_match('/^(content|settings)\.([A-Za-z0-9_-]+)$/', $path, $m) !== 1
            ) {
                continue;
            }
            $source = $spec['source'] ?? null;
            $field = $spec['field'] ?? null;
            if (!is_string($source) || !is_string($field)) {
                continue;
            }
            $fallback = is_string($spec['fallback'] ?? null) ? $spec['fallback'] : '';

            $static = $this->bindings?->staticValue($source, $field);
            if ($static !== null) {
                $value = $static === '' ? $fallback : $static;
                $this->addDep("bind:$source:$field");
            } else {
                $token = '__NCB' . (++$this->bindSeq) . '__';
                $value = $token;
                $pending[] = [
                    'token' => $token,
                    'source' => $source,
                    'field' => $field,
                    'fallback' => $fallback,
                    'escape' => self::escapeFor($path, $m[2]),
                ];
            }

            if ($m[1] === 'content') {
                $content[$m[2]] = $value;
            } else {
                $settings[$m[2]] = $value;
            }
        }

        return [new Node(
            id: $node->id,
            type: $node->type,
            content: $content,
            settings: $settings,
            style: $node->style,
            visibility: $node->visibility,
            bind: $node->bind,
            children: $node->children,
            ref: $node->ref,
        ), $pending];
    }

    /** Come escapare il valore runtime: URL per attributi, HTML per content.html, testo altrove. */
    private static function escapeFor(string $path, string $key): string
    {
        if ($path === 'content.html') {
            return 'html';
        }
        foreach (['src', 'url', 'href', 'poster'] as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return 'url';
            }
        }

        return 'text';
    }

    private function addDep(string $dep): void
    {
        if (!in_array($dep, $this->deps, true)) {
            $this->deps[] = $dep;
        }
    }

    public function renderChildren(Node $node): string
    {
        return implode('', array_map(fn (Node $child): string => $this->renderNode($child), $node->children));
    }

    public function classes(Node $node, string ...$extra): string
    {
        $slash = strrpos($node->type, '/');
        $slug = $slash === false ? $node->type : substr($node->type, $slash + 1);

        $parts = ['nc-' . $slug, ...($this->css->classes[$node->id] ?? []), ...$extra];

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
    }

    public function slot(string $blockType, array $data, string $fallbackHtml): string
    {
        $n = count($this->slots);
        $this->slots[$n] = ['block' => $blockType, 'data' => $data];

        return '<!--nc:s ' . $n . '-->' . $fallbackHtml . '<!--/nc:s-->';
    }

    public function template(Node $card): array
    {
        $sub = new self($this->registry, $this->tokens, $this->css, null, $this->markIds, $this->placeholders);
        // Nessuna immagine della scheda è candidata LCP: si ripete per ogni elemento.
        $sub->lcpNodeId = '';
        $html = $sub->renderNode($card);
        foreach (array_keys($sub->blockTypes) as $type) {
            $this->blockTypes[$type] = true;
        }
        foreach ($sub->deps as $dep) {
            $this->addDep($dep);
        }

        return ['html' => $html, 'slots' => $sub->slots];
    }

    public function claimLcp(string $nodeId): bool
    {
        $this->lcpNodeId ??= $nodeId;

        return $this->lcpNodeId === $nodeId;
    }

    public function richText(string $html): string
    {
        return RichText::clean($html);
    }

    public function value(string $v): string
    {
        return $this->tokens->resolve($v) ?? $v;
    }

    /** @return array<int,array{block:string,data:array<string,mixed>}> */
    public function slots(): array
    {
        return $this->slots;
    }

    /** @return list<string> */
    public function blockTypes(): array
    {
        return array_keys($this->blockTypes);
    }

    public function lcpNodeId(): ?string
    {
        return $this->lcpNodeId;
    }

    /** @return list<string> */
    public function deps(): array
    {
        return $this->deps;
    }
}
