<?php

declare(strict_types=1);

namespace Greenlight\Core\Artifact;

use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Immutable metadata for one retained test attachment.
 *
 * storageKey is internal worker-to-orchestrator state and is removed before
 * reporters receive the result.
 */
final readonly class Attachment implements WireSerializable
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
        private ?string $storageKey = null,
    ) {
        if ($name === ''
            || $mediaType === ''
            || $sizeBytes < 0
            || \preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1
            || $attempt < 1
            || $path === ''
            || \str_contains($path, "\0")
        ) {
            throw new \InvalidArgumentException('Attachment metadata is invalid.');
        }
    }

    public function published(): self
    {
        return new self(
            $this->name,
            $this->kind,
            $this->mediaType,
            $this->sizeBytes,
            $this->sha256,
            $this->attempt,
            $this->path,
            $this->retention,
        );
    }

    /**
     * @internal worker-to-orchestrator storage coordinate
     */
    public function storageKey(): ?string
    {
        return $this->storageKey;
    }

    #[\Override]
    public function toWire(): array
    {
        $payload = [
            'name' => $this->name,
            'kind' => $this->kind->value,
            'mediaType' => $this->mediaType,
            'sizeBytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
            'attempt' => $this->attempt,
            'path' => $this->path,
            'retention' => $this->retention->value,
        ];

        if ($this->storageKey !== null) {
            $payload['storageKey'] = $this->storageKey;
        }

        return $payload;
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
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
            \array_key_exists('storageKey', $payload) ? Wire::nullableString($payload, 'storageKey') : null,
        );
    }
}
