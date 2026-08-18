<?php

declare(strict_types=1);

namespace Greenlight\Config;

use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Contains the output directory and safety limits for test attachments.
 *
 * @internal
 */
final readonly class ArtifactConfiguration implements WireSerializable
{
    public const string DEFAULT_DIRECTORY = 'build/greenlight-artifacts';
    public const int DEFAULT_MAX_ATTACHMENTS_PER_TEST = 32;
    public const int DEFAULT_MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;
    public const int DEFAULT_MAX_TEST_BYTES = 100 * 1024 * 1024;
    public const int DEFAULT_MAX_RUN_ATTACHMENTS = 10_000;
    public const int DEFAULT_MAX_RUN_BYTES = 1024 * 1024 * 1024;

    public function __construct(
        public string $directory = self::DEFAULT_DIRECTORY,
        public int $maxAttachmentsPerTest = self::DEFAULT_MAX_ATTACHMENTS_PER_TEST,
        public int $maxAttachmentBytes = self::DEFAULT_MAX_ATTACHMENT_BYTES,
        public int $maxTestBytes = self::DEFAULT_MAX_TEST_BYTES,
        public int $maxRunAttachments = self::DEFAULT_MAX_RUN_ATTACHMENTS,
        public int $maxRunBytes = self::DEFAULT_MAX_RUN_BYTES,
    ) {}

    public function withDirectory(string $directory): self
    {
        return new self(
            $directory,
            $this->maxAttachmentsPerTest,
            $this->maxAttachmentBytes,
            $this->maxTestBytes,
            $this->maxRunAttachments,
            $this->maxRunBytes,
        );
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'directory' => $this->directory,
            'maxAttachmentsPerTest' => $this->maxAttachmentsPerTest,
            'maxAttachmentBytes' => $this->maxAttachmentBytes,
            'maxTestBytes' => $this->maxTestBytes,
            'maxRunAttachments' => $this->maxRunAttachments,
            'maxRunBytes' => $this->maxRunBytes,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $directory = Wire::nonEmptyString($payload, 'directory');

        if (\str_contains($directory, "\0")) {
            throw InvalidWirePayload::wrongType(
                'directory',
                'an artifact directory without null bytes',
                $directory,
            );
        }

        return new self(
            $directory,
            \max(1, Wire::int($payload, 'maxAttachmentsPerTest')),
            \max(1, Wire::int($payload, 'maxAttachmentBytes')),
            \max(1, Wire::int($payload, 'maxTestBytes')),
            \max(1, Wire::int($payload, 'maxRunAttachments')),
            \max(1, Wire::int($payload, 'maxRunBytes')),
        );
    }
}
