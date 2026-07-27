<?php

declare(strict_types=1);

namespace Greenlight\Core\Artifact;

/**
 * Adds structured values, text, binary data, and files to one test attempt.
 *
 * Implementations copy input immediately. The attachment store saves content
 * separately. Test results contain only metadata and published paths.
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
