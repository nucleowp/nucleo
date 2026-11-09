<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

use InvalidArgumentException;
use Nucleo\Bindings\BindingSource;
use Nucleo\Blocks\Node;
use Nucleo\Blocks\Registry;
use Nucleo\Css\Breakpoints;
use Nucleo\Css\StyleCompiler;
use Nucleo\Seo\SchemaRegistry;
use Nucleo\Tokens\TokenResolver;

/** Compiles a validated document into static HTML, instance CSS and block assets. */
final class Compiler
{
    public function __construct(
        private readonly Registry $registry,
        private readonly TokenResolver $tokens,
        private readonly Breakpoints $breakpoints,
    ) {
    }

    /**
     * @param array<string,mixed> $doc
     * @param bool $markIds true solo per l'anteprima dell'editor: ogni nodo porta data-nc-id
     * @param array<string,string> $placeholders anteprima dell'editor: nodi da mostrare come segnaposto (id => motivo)
     */
    public function compile(array $doc, ?BindingSource $bindings = null, bool $markIds = false, array $placeholders = []): CompileResult
    {
        $rawRoot = $doc['root'] ?? null;
        if (!is_array($rawRoot)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(__('Document has no root', 'nucleo'));
        }

        $root = self::toNode($rawRoot);
        // Layout, sezioni e popup stanno nella stessa pagina: un prefisso per documento evita classi uguali (.nc-n1).
        $scope = ($doc['kind'] ?? 'page') === 'page'
            ? ''
            : substr(sha1((string) json_encode([$doc['kind'] ?? '', $doc['meta'] ?? [], $rawRoot])), 0, 4) . '-';
        $css = (new StyleCompiler($this->tokens, $this->breakpoints, $this->registry->partSelectors()))->compile($root, $scope);

        $ctx = new CompileContext($this->registry, $this->tokens, $css, $bindings, $markIds, $placeholders, $this->breakpoints->minWidths);
        $html = SchemaRegistry::consolidate($ctx->renderNode($root));

        $types = $ctx->blockTypes();
        $artifact = $this->registry->toArtifact();

        $styles = [];
        $scripts = [];
        foreach ($types as $type) {
            $entry = $artifact[$type];
            if ($entry['style'] !== null && !in_array($entry['style'], $styles, true)) {
                $styles[] = $entry['style'];
            }
            if ($entry['script'] !== null) {
                $scripts[] = ['type' => $type, 'src' => $entry['script'], 'strategy' => $entry['strategy']];
            }
        }

        return new CompileResult(
            $html,
            $css->css,
            $types,
            $ctx->slots(),
            ['styles' => $styles, 'scripts' => $scripts],
            $ctx->lcpNodeId(),
            $ctx->deps(),
        );
    }

    /** @param array<string,mixed> $raw */
    private static function toNode(array $raw): Node
    {
        $children = [];
        foreach (self::arrayOf($raw, 'children') as $child) {
            if (is_array($child)) {
                $children[] = self::toNode($child);
            }
        }

        return new Node(
            id: is_string($raw['id'] ?? null) ? $raw['id'] : '',
            type: is_string($raw['type'] ?? null) ? $raw['type'] : '',
            content: self::arrayOf($raw, 'content'),
            settings: self::arrayOf($raw, 'settings'),
            style: self::arrayOf($raw, 'style'),
            visibility: self::arrayOf($raw, 'visibility'),
            bind: self::arrayOf($raw, 'bind'),
            children: $children,
            ref: is_string($raw['ref'] ?? null) ? $raw['ref'] : null,
        );
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private static function arrayOf(array $raw, string $key): array
    {
        $value = $raw[$key] ?? [];

        return is_array($value) ? $value : [];
    }
}
