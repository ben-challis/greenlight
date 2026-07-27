<?php

declare(strict_types=1);

namespace Greenlight\Runner\Artifact;

use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Identifies the private attachment staging area of a run for a worker.
 *
 * @internal
 */
final readonly class ArtifactSession implements WireSerializable
{
    /**
     * @param non-empty-string $stagingDirectory
     * @param non-empty-string $publicDirectory
     */
    public function __construct(
        public string $stagingDirectory,
        public string $publicDirectory,
    ) {}

    #[\Override]
    public function toWire(): array
    {
        return [
            'stagingDirectory' => $this->stagingDirectory,
            'publicDirectory' => $this->publicDirectory,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'stagingDirectory'),
            Wire::nonEmptyString($payload, 'publicDirectory'),
        );
    }
}
