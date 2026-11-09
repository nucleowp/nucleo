<?php

declare(strict_types=1);

namespace Nucleo\Content;

use Nucleo\Schema\JsonSchemaValidator;
use RuntimeException;

/** Regole del modello dei contenuti che lo schema JSON non esprime (docs/contracts/content-model.md). */
final class ModelValidator
{
    private const SCHEMA_FILE = __DIR__ . '/../../schema/content-model.schema.json';

    /** Slug riservati da WordPress. Il prefisso wp_ si controlla a parte. */
    private const RESERVED = ['post', 'page', 'attachment', 'revision', 'nav_menu_item', 'action', 'author', 'order', 'theme', 'category', 'post_tag', 'link_category', 'post_format'];

    private const POST_TYPE_MAX = 20;

    private const TAXONOMY_MAX = 32;

    /** @var array<string,mixed>|null */
    private ?array $schema = null;

    private readonly JsonSchemaValidator $schemaValidator;

    private readonly \Closure $registered;

    /** @param callable(string,string):bool $registered kind 'postType'|'taxonomy', slug */
    public function __construct(callable $registered)
    {
        $this->registered = \Closure::fromCallable($registered);
        $this->schemaValidator = new JsonSchemaValidator();
    }

    /**
     * Se lo schema dà errori restituisce solo quelli. Altrimenti applica le regole.
     *
     * @param array<string,mixed> $model
     * @return list<ModelError>
     */
    public function validate(array $model): array
    {
        $errors = [];
        foreach ($this->schemaValidator->validate($model, $this->schema()) as $error) {
            $errors[] = new ModelError('schema', $error->path, $error->message);
        }
        if ($errors !== []) {
            return $errors;
        }

        $postTypes = $model['postTypes'] ?? [];
        $taxonomies = $model['taxonomies'] ?? [];
        $fieldGroups = $model['fieldGroups'] ?? [];

        /** @var array{postType: array<string,true>, taxonomy: array<string,true>} $declared */
        $declared = ['postType' => [], 'taxonomy' => []];
        foreach ($postTypes as $postType) {
            $declared['postType'][$postType['slug']] = true;
        }
        foreach ($taxonomies as $taxonomy) {
            $declared['taxonomy'][$taxonomy['slug']] = true;
        }

        $seenPostTypes = [];
        foreach ($postTypes as $i => $postType) {
            $path = "/postTypes/$i/slug";
            $this->checkSlug($postType['slug'], 'postType', self::POST_TYPE_MAX, $path, $errors);
            $this->checkUnique($postType['slug'], $path, $seenPostTypes, $errors);
        }

        $seenTaxonomies = [];
        foreach ($taxonomies as $i => $taxonomy) {
            $path = "/taxonomies/$i/slug";
            $this->checkSlug($taxonomy['slug'], 'taxonomy', self::TAXONOMY_MAX, $path, $errors);
            $this->checkUnique($taxonomy['slug'], $path, $seenTaxonomies, $errors);
            foreach ($taxonomy['postTypes'] ?? [] as $k => $slug) {
                $this->checkTarget('postType', $slug, "/taxonomies/$i/postTypes/$k", $declared, $errors);
            }
        }

        $seenIds = [];
        /** @var array<string,array<string,true>> $seenKeys chiavi dei campi per posizione */
        $seenKeys = [];
        foreach ($fieldGroups as $g => $group) {
            $base = "/fieldGroups/$g";
            $this->checkUnique($group['id'], "$base/id", $seenIds, $errors);

            $location = $group['location'];
            if (isset($location['postType'])) {
                $this->checkTarget('postType', $location['postType'], "$base/location/postType", $declared, $errors);
                $scope = 'postType:' . $location['postType'];
            } else {
                $this->checkTarget('taxonomy', $location['taxonomy'], "$base/location/taxonomy", $declared, $errors);
                $scope = 'taxonomy:' . $location['taxonomy'];
            }

            $seenKeys[$scope] ??= [];
            foreach ($group['fields'] as $j => $field) {
                $this->checkUnique($field['key'], "$base/fields/$j/key", $seenKeys[$scope], $errors);
                $this->checkField($field, "$base/fields/$j", $declared, $errors);
            }
        }

        return array_values($errors);
    }

    /**
     * @param array<string,true> $seen
     * @param list<ModelError> $errors
     */
    private function checkUnique(string $value, string $path, array &$seen, array &$errors): void
    {
        if (isset($seen[$value])) {
            $errors[] = new ModelError('duplicate', $path, sprintf(/* translators: %s: repeated value */ __('"%s" is already used', 'nucleo'), $value));
        }
        $seen[$value] = true;
    }

    /**
     * Lunghezza e slug riservati. Un tipo già registrato da WordPress o da un altro plugin è riservato.
     *
     * @param list<ModelError> $errors
     */
    private function checkSlug(string $slug, string $kind, int $max, string $path, array &$errors): void
    {
        if (strlen($slug) > $max) {
            $errors[] = new ModelError('slug_length', $path, sprintf(/* translators: %d: maximum length */ __('maximum %d characters', 'nucleo'), $max));
        }
        if (in_array($slug, self::RESERVED, true) || str_starts_with($slug, 'wp_')) {
            $errors[] = new ModelError('reserved_slug', $path, sprintf(/* translators: %s: slug */ __('"%s" is reserved by WordPress', 'nucleo'), $slug));
        } elseif (($this->registered)($kind, $slug)) {
            $errors[] = new ModelError('reserved_slug', $path, sprintf(
                /* translators: %s: slug */
                __('"%s" is already registered by WordPress, another plugin or the theme (model-get lists it under "unmodeled" with registeredBy). Choose another slug, or keep that registration and add only a field group with location on it.', 'nucleo'),
                $slug,
            ));
        }
    }

    /**
     * @param array{postType: array<string,true>, taxonomy: array<string,true>} $declared
     * @param list<ModelError> $errors
     */
    private function checkTarget(string $kind, string $slug, string $path, array $declared, array &$errors): void
    {
        if (!isset($declared[$kind][$slug]) && !($this->registered)($kind, $slug)) {
            $errors[] = new ModelError('unknown_target', $path, sprintf(/* translators: 1: slug, 2: kind */ __('"%1$s" is not a known %2$s: declare it in the model or check the slug', 'nucleo'), $slug, $kind));
        }
    }

    /**
     * @param array<string,mixed> $field
     * @param array{postType: array<string,true>, taxonomy: array<string,true>} $declared
     * @param list<ModelError> $errors
     */
    private function checkField(array $field, string $path, array $declared, array &$errors): void
    {
        if ($field['type'] === 'relation') {
            foreach ($field['postTypes'] ?? [] as $k => $slug) {
                $this->checkTarget('postType', $slug, "$path/postTypes/$k", $declared, $errors);
            }
        }

        if ($field['type'] === 'repeater') {
            foreach ($field['fields'] ?? [] as $k => $sub) {
                $subPath = "$path/fields/$k";
                if ($sub['type'] === 'repeater') {
                    $errors[] = new ModelError('nested_repeater', $subPath, __('a repeater cannot contain another repeater', 'nucleo'));
                }
                $this->checkField($sub, $subPath, $declared, $errors);
            }
        }
    }

    /** @return array<string,mixed> */
    private function schema(): array
    {
        if ($this->schema === null) {
            $raw = file_get_contents(self::SCHEMA_FILE);
            $decoded = $raw === false ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio per JSON e WP_Error, escapato da chi lo stampa in HTML.
                throw new RuntimeException(sprintf(/* translators: %s: schema file path */ __('content model schema is not readable: %s', 'nucleo'), self::SCHEMA_FILE));
            }
            $this->schema = $decoded;
        }

        return $this->schema;
    }
}
