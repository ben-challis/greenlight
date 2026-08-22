<?php

declare(strict_types=1);

namespace Greenlight\Runner\Artifact;

use Greenlight\Wire\InvalidWirePayload;
use Greenlight\Wire\Wire;
use Greenlight\Wire\WireCommunicationFailed;
use Greenlight\Wire\WireSerializable;

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
            self::directoryFromWire($payload, 'stagingDirectory'),
            self::directoryFromWire($payload, 'publicDirectory'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param 'publicDirectory'|'stagingDirectory' $key
     *
     * @return non-empty-string
     * @throws WireCommunicationFailed
     */
    private static function directoryFromWire(array $payload, string $key): string
    {
        $directory = Wire::nonEmptyString($payload, $key);

        if (\str_contains($directory, "\0")) {
            throw InvalidWirePayload::wrongType($key, 'a directory without null bytes', $directory);
        }

        return $directory;
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
