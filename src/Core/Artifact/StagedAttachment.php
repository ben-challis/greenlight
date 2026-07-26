<?php

declare(strict_types=1);

namespace Greenlight\Core\Artifact;

use Greenlight\Core\Wire\Wire;

/**
 * Attachment metadata with its private worker-to-orchestrator coordinate.
 *
 * @internal
 */
final readonly class StagedAttachment extends Attachment
{
    public function __construct(
        string $name,
        AttachmentKind $kind,
        string $mediaType,
        int $sizeBytes,
        string $sha256,
        int $attempt,
        string $path,
        AttachmentRetention $retention = AttachmentRetention::OnFailure,
        public string $storageKey = '',
    ) {
        parent::__construct(
            $name,
            $kind,
            $mediaType,
            $sizeBytes,
            $sha256,
            $attempt,
            $path,
            $retention,
        );

        if ($storageKey === '') {
            throw new \InvalidArgumentException('Attachment storage key is invalid.');
        }
    }

    public function published(): Attachment
    {
        return new Attachment(
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

    #[\Override]
    public function toWire(): array
    {
        return [
            ...parent::toWire(),
            'storageKey' => $this->storageKey,
        ];
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
            Wire::nonEmptyString($payload, 'storageKey'),
        );
    }
}
