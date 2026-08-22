<?php

declare(strict_types=1);

namespace Greenlight\Artifact;

/**
 * Rejects attachment calls outside a test attempt that the executor owns.
 *
 * @internal
 */
final class UnavailableAttachments implements Attachments
{
    /**
     * @throws AttachmentError
     */
    #[\Override]
    public function value(
        string $name,
        mixed $value,
        AttachmentRetention $retention = AttachmentRetention::OnFailure,
    ): void {
        throw AttachmentError::unavailable();
    }

    /**
     * @throws AttachmentError
     */
    #[\Override]
    public function text(
        string $name,
        string $text,
        string $mediaType = 'text/plain; charset=utf-8',
        AttachmentRetention $retention = AttachmentRetention::OnFailure,
    ): void {
        throw AttachmentError::unavailable();
    }

    /**
     * @throws AttachmentError
     */
    #[\Override]
    public function bytes(
        string $name,
        string $bytes,
        string $mediaType = 'application/octet-stream',
        AttachmentRetention $retention = AttachmentRetention::OnFailure,
    ): void {
        throw AttachmentError::unavailable();
    }

    /**
     * @throws AttachmentError
     */
    #[\Override]
    public function file(
        string $name,
        string $sourcePath,
        ?string $mediaType = null,
        AttachmentRetention $retention = AttachmentRetention::OnFailure,
    ): void {
        throw AttachmentError::unavailable();
    }
}
