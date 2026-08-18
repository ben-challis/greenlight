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
        $this->stagingDirectory = $this->validatedDirectory($stagingDirectory, 'staging');
        $this->publicDirectory = $this->validatedDirectory($publicDirectory, 'public');
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

    /**
     * @param 'public'|'staging' $role
     *
     * @return non-empty-string
     */
    private function validatedDirectory(string $directory, string $role): string
    {
        if ($directory === '') {
            throw new \InvalidArgumentException(\sprintf('Artifact %s directory must not be empty.', $role));
        }

        if (\str_contains($directory, "\0")) {
            throw new \InvalidArgumentException(\sprintf('Artifact %s directory must not contain a null byte.', $role));
        }

        return $directory;
    }
}
