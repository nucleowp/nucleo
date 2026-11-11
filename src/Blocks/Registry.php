<?php

declare(strict_types=1);

namespace Nucleo\Blocks;

defined('ABSPATH') || exit;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** Registry of blocks: reads block.nucleo.json manifests and keeps one renderer per block type. */
final class Registry
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/D';
    private const STRATEGIES = ['eager', 'visible', 'interaction', 'idle'];

    /** @var array<string,Manifest> */
    private array $manifests = [];

    /** @var array<string,Renderer> */
    private array $renderers = [];

    /** Loads every immediate subfolder of $dir that contains a block.nucleo.json. */
    public static function fromDirectory(string $dir): self
    {
        if (!is_dir($dir)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: directory path */ __('Blocks directory not found: %s', 'nucleo'), $dir));
        }

        $registry = new self();
        foreach (glob(rtrim($dir, '/') . '/*/block.nucleo.json') ?: [] as $file) {
            $registry->register(dirname($file));
        }

        return $registry;
    }

    /** Registers the block whose folder is $blockDir (the one holding block.nucleo.json). */
    public function register(string $blockDir): void
    {
        $dir = realpath($blockDir);
        if ($dir === false || !is_dir($dir)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: block directory path */ __('Block directory not found: %s', 'nucleo'), $blockDir));
        }

        $manifest = $this->parse($dir);
        if (isset($this->manifests[$manifest->name])) {
            throw new InvalidArgumentException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                sprintf(/* translators: 1: block name, 2: directory path */ __('Duplicate block name "%1$s": %2$s/block.nucleo.json', 'nucleo'), $manifest->name, $dir)
            );
        }

        $this->manifests[$manifest->name] = $manifest;
    }

    public function has(string $type): bool
    {
        return isset($this->manifests[$type]);
    }

    public function get(string $type): ?Manifest
    {
        return $this->manifests[$type] ?? null;
    }

    /** Gruppi di stile di ogni blocco: il manifest ne toglie qualcuno solo con supports.styleExclude. */
    public const BASE_STYLE = ['size', 'spacing', 'typography', 'color', 'border', 'motion'];

    /**
     * supports.style con i gruppi di base aggiunti in coda, tranne quelli di supports.styleExclude.
     * @param array<string,mixed> $supports
     * @return array<string,mixed>
     */
    private static function withBaseStyle(array $supports): array
    {
        $declared = is_array($supports['style'] ?? null) ? array_values(array_filter($supports['style'], 'is_string')) : [];
        $exclude = is_array($supports['styleExclude'] ?? null) ? $supports['styleExclude'] : [];
        $supports['style'] = array_values(array_unique([...$declared, ...array_diff(self::BASE_STYLE, $exclude)]));

        return $supports;
    }

    /** @return array<string,array<string,string>> tipo di blocco → nome della parte → selettore dentro il nodo */
    public function partSelectors(): array
    {
        $out = [];
        foreach ($this->all() as $manifest) {
            if ($manifest->parts !== []) {
                $out[$manifest->name] = $manifest->parts;
            }
        }

        return $out;
    }

    /** @return array<string,Manifest> sorted by block name */
    public function all(): array
    {
        $all = $this->manifests;
        ksort($all);

        return $all;
    }

    /** Returns the single renderer instance for $type, loading Renderer.php from the block folder if needed. */
    public function renderer(string $type): Renderer
    {
        if (isset($this->renderers[$type])) {
            return $this->renderers[$type];
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
        $manifest = $this->manifests[$type] ?? throw new InvalidArgumentException(sprintf(/* translators: %s: block type */ __('Unknown block: %s', 'nucleo'), $type));
        $class = $manifest->renderer;

        if (!class_exists($class)) {
            $file = $manifest->dir . '/Renderer.php';
            if (is_file($file)) {
                require_once $file;
            }
        }

        if (!class_exists($class) || !is_subclass_of($class, Renderer::class)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new RuntimeException(sprintf(/* translators: 1: renderer class, 2: block type */ __('Renderer "%1$s" not found for block %2$s', 'nucleo'), $class, $type));
        }

        $renderer = new $class();
        $this->renderers[$type] = $renderer;

        return $renderer;
    }

    /**
     * @return array<string,array{version:int,style:?string,script:?string,strategy:string,renderer:string}>
     */
    public function toArtifact(): array
    {
        $artifact = [];
        foreach ($this->all() as $name => $manifest) {
            $artifact[$name] = [
                'version' => $manifest->version,
                'style' => $this->assetPath($manifest, 'style'),
                'script' => $this->assetPath($manifest, 'script'),
                'strategy' => $manifest->assets['strategy'] ?? 'eager',
                'renderer' => $manifest->renderer,
            ];
        }

        return $artifact;
    }

    private function parse(string $dir): Manifest
    {
        $file = $dir . '/block.nucleo.json';
        $raw = is_file($file) ? file_get_contents($file) : false;
        if ($raw === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: file path */ __('Manifest not readable: %s', 'nucleo'), $file));
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: 1: file path, 2: JSON error message */ __('Invalid JSON in %1$s: %2$s', 'nucleo'), $file, $e->getMessage()), 0, $e);
        }

        if (!is_array($data)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: file path */ __('The manifest must be a JSON object: %s', 'nucleo'), $file));
        }

        $name = $data['name'] ?? null;
        if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: file path */ __('Invalid block name in %s', 'nucleo'), $file));
        }

        $version = $data['version'] ?? null;
        if (!is_int($version) || $version < 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: file path */ __('Field "version" is missing or invalid in %s', 'nucleo'), $file));
        }

        $title = $data['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: file path */ __('Field "title" is missing in %s', 'nucleo'), $file));
        }

        $children = $data['children'] ?? false;
        if (!is_bool($children) && !$this->isNameList($children)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: file path */ __('Field "children" is invalid in %s', 'nucleo'), $file));
        }

        $parents = $data['parents'] ?? null;
        if ($parents !== null && !$this->isNameList($parents)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: file path */ __('Field "parents" is invalid in %s', 'nucleo'), $file));
        }

        $assets = $this->arrayField($data, 'assets', $file);
        foreach (['style', 'script'] as $key) {
            if (isset($assets[$key]) && !is_string($assets[$key])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new InvalidArgumentException(sprintf(/* translators: 1: asset key, 2: file path */ __('assets.%1$s is invalid in %2$s', 'nucleo'), $key, $file));
            }
        }

        $strategy = $assets['strategy'] ?? 'eager';
        if (!is_string($strategy) || !in_array($strategy, self::STRATEGIES, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: %s: file path */ __('assets.strategy is invalid in %s', 'nucleo'), $file));
        }
        $assets['strategy'] = $strategy;

        return new Manifest(
            name: $name,
            version: $version,
            title: $title,
            dir: $dir,
            category: $this->stringField($data, 'category', 'general', $file),
            description: $this->stringField($data, 'description', '', $file),
            aiHints: $this->stringField($data, 'aiHints', '', $file),
            content: $this->arrayField($data, 'content', $file),
            settings: $this->arrayField($data, 'settings', $file),
            supports: self::withBaseStyle($this->arrayField($data, 'supports', $file)),
            children: $children,
            parents: $parents,
            assets: $assets,
            semantics: $this->arrayField($data, 'semantics', $file),
            renderer: $this->stringField($data, 'renderer', '', $file),
            deps: $this->depsField($data, $file),
            parts: array_filter($this->arrayField($data, 'parts', $file), 'is_string'),
        );
    }

    /** @param array<string,mixed> $data */
    private function stringField(array $data, string $key, string $default, string $file): string
    {
        $value = $data[$key] ?? $default;
        if (!is_string($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: 1: field name, 2: file path */ __('Field "%1$s" must be a string in %2$s', 'nucleo'), $key, $file));
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function arrayField(array $data, string $key, string $file): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new InvalidArgumentException(sprintf(/* translators: 1: field name, 2: file path */ __('Field "%1$s" must be an object in %2$s', 'nucleo'), $key, $file));
        }

        return $value;
    }

    private function isNameList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_string($item) || preg_match(self::NAME_PATTERN, $item) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function assetPath(Manifest $manifest, string $key): ?string
    {
        $file = $manifest->assets[$key] ?? null;
        if (!is_string($file)) {
            return null;
        }
        // Un blocco può usare il file di un blocco vicino ("../add-to-cart/view.js"): percorso senza "..",
        // così l'URL coincide e il loader carica il file una volta sola.
        $path = $manifest->dir . '/' . $file;
        do {
            $path = (string) preg_replace('#/(?!\.\.?/)[^/]+/\.\./#', '/', $path, 1, $count);
        } while ($count > 0);

        return $path;
    }

    /**
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private function depsField(array $data, string $file): array
    {
        $deps = $data['deps'] ?? [];
        if (!is_array($deps) || !array_is_list($deps)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
            throw new \InvalidArgumentException(sprintf(/* translators: %s: file path */ __('%s: deps must be a list of strings.', 'nucleo'), $file));
        }
        $out = [];
        foreach ($deps as $dep) {
            if (!is_string($dep) || preg_match('/^[a-z_]+(?::[A-Za-z0-9_.{}-]+)*$/', $dep) !== 1) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new \InvalidArgumentException(sprintf(/* translators: %s: file path */ __('%s: invalid dep.', 'nucleo'), $file));
            }
            $out[] = $dep;
        }

        return $out;
    }
}
