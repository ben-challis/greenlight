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
     * @var non-empty-string
     */
    public string $stagingDirectory;

    /**
     * @var non-empty-string
     */
    public string $publicDirectory;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $stagingDirectory,
        string $publicDirectory,
    ) {
        if ($stagingDirectory === '') {
            throw new \InvalidArgumentException('Artifact staging directory must not be empty.');
        }

        if ($publicDirectory === '') {
            throw new \InvalidArgumentException('Artifact public directory must not be empty.');
        }

        $this->stagingDirectory = $stagingDirectory;
        $this->publicDirectory = $publicDirectory;
    }

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
