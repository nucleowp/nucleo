<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

/**
 * Outcome of DocumentService. toArray() is what abilities return:
 * {ok, postId, revision, errors: list<ValidationError array>, quality: QualityReport::toArray()+{blocking}, error?: {code,message}, previewUrl?, diff?}
 */
final class SaveResult
{
    /**
     * @param list<array<string,mixed>> $errors
     * @param array<string,mixed> $quality
     * @param array{code:string,message:string}|null $error codes: invalid_document, quality_blocking, conflict, not_found, forbidden, patch_failed
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $postId = 0,
        public readonly int $revision = 0,
        public readonly array $errors = [],
        public readonly array $quality = [],
        public readonly ?array $error = null,
        public readonly ?string $previewUrl = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'ok' => $this->ok, 'postId' => $this->postId, 'revision' => $this->revision, 'errors' => $this->errors,
            'quality' => $this->quality, 'error' => $this->error, 'previewUrl' => $this->previewUrl,
        ], static fn ($v) => $v !== null);
    }
}
