<?php

declare(strict_types=1);

namespace Greenlight\Artifact;

use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * Fixed metadata for one retained test attachment.
 *
 * @phpstan-consistent-constructor
 */
readonly class Attachment
{
    public function __construct(
        public string $name,
        public AttachmentKind $kind,
        public string $mediaType,
        public int $sizeBytes,
        public string $sha256,
        public int $attempt,
        public string $path,
        public AttachmentRetention $retention = AttachmentRetention::OnFailure,
    ) {
        if ($name === ''
            || !AttachmentMediaType::isValid($mediaType)
            || $sizeBytes < 0
            || \preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1
            || $attempt < 1
            || $path === ''
            || \str_contains($path, "\0")
        ) {
            throw new \InvalidArgumentException('Attachment metadata is invalid.');
        }
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'name' => $this->name,
            'kind' => $this->kind->value,
            'mediaType' => $this->mediaType,
            'sizeBytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
            'attempt' => $this->attempt,
            'path' => $this->path,
            'retention' => $this->retention->value,
        ];
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $payload
     * @throws WireCommunicationFailed
     */
    public static function fromWire(array $payload): static
    {
        return new static(
            Wire::nonEmptyString($payload, 'name'),
            Wire::enum($payload, 'kind', AttachmentKind::class),
            Wire::nonEmptyString($payload, 'mediaType'),
            \max(0, Wire::int($payload, 'sizeBytes')),
            Wire::nonEmptyString($payload, 'sha256'),
            \max(1, Wire::int($payload, 'attempt')),
            Wire::nonEmptyString($payload, 'path'),
            \array_key_exists('retention', $payload)
                ? Wire::enum($payload, 'retention', AttachmentRetention::class)
                : AttachmentRetention::OnFailure,
        );
    }
}
