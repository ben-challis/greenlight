<?php

declare(strict_types=1);

namespace Greenlight\Core\Artifact;

/**
 * Attaches structured values, text, binary bytes, and files to one test attempt.
 *
 * Implementations copy input immediately. Attachment content is persisted out
 * of band; test results carry metadata and published paths only.
 */
interface Attachments
{
    public function value(
        string $name,
        mixed $value,
        AttachmentRetention $retention = AttachmentRetention::OnFailure,
    ): void;

    public function text(
        string $name,
        string $text,
        string $mediaType = 'text/plain; charset=utf-8',
        AttachmentRetention $retention = AttachmentRetention::OnFailure,
    ): void;

    public function bytes(
        string $name,
        string $bytes,
        string $mediaType = 'application/octet-stream',
        AttachmentRetention $retention = AttachmentRetention::OnFailure,
    ): void;

    public function file(
        string $name,
        string $sourcePath,
        ?string $mediaType = null,
        AttachmentRetention $retention = AttachmentRetention::OnFailure,
    ): void;
}
