<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Core\Wire\InvalidWirePayload;

/**
 * fromDecoded() examines input JSON. It returns null if a part has an
 * incorrect form. Thus, discovery parses the file again after a corrupt cache
 * entry. jsonSerialize() produces the same form.
 *
 * @internal
 */
final readonly class DiscoveryCacheEntry implements \JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $entries
     * @param array<non-empty-string, array{mtime: int, size: int}> $dependencies
     */
    public function __construct(
        public int $mtime,
        public int $size,
        public array $entries,
        public array $dependencies = [],
    ) {}

    /**
     * @param array<mixed> $decoded
     */
    public static function fromDecoded(array $decoded): ?self
    {
        if (!\is_int($decoded['mtime'] ?? null) || !\is_int($decoded['size'] ?? null) || !\is_array($decoded['entries'] ?? null)) {
            return null;
        }

        $payloads = [];

        foreach ($decoded['entries'] as $payload) {
            if (!\is_array($payload)) {
                return null;
            }

            $normalized = [];

            foreach ($payload as $key => $value) {
                if (!\is_string($key)) {
                    return null;
                }

                $normalized[$key] = $value;
            }

            $payloads[] = $normalized;
        }

        $dependencies = [];
        $decodedDependencies = $decoded['dependencies'] ?? [];

        if (!\is_array($decodedDependencies)) {
            return null;
        }

        foreach ($decodedDependencies as $path => $stat) {
            if (
                !\is_string($path)
                || $path === ''
                || !\is_array($stat)
                || !\is_int($stat['mtime'] ?? null)
                || !\is_int($stat['size'] ?? null)
            ) {
                return null;
            }

            $dependencies[$path] = ['mtime' => $stat['mtime'], 'size' => $stat['size']];
        }

        return new self($decoded['mtime'], $decoded['size'], $payloads, $dependencies);
    }

    /**
     * Returns null if a stored plan entry cannot decode.
     *
     * @return list<PlanEntry>|null
     */
    public function planEntries(): ?array
    {
        $entries = [];

        try {
            foreach ($this->entries as $payload) {
                $entries[] = PlanEntry::fromWire($payload);
            }
        } catch (\InvalidArgumentException|InvalidWirePayload) {
            return null;
        }

        return $entries;
    }

    /**
     * @return array{
     *     mtime: int,
     *     size: int,
     *     entries: list<array<string, mixed>>,
     *     dependencies: array<non-empty-string, array{mtime: int, size: int}>
     * }
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'mtime' => $this->mtime,
            'size' => $this->size,
            'entries' => $this->entries,
            'dependencies' => $this->dependencies,
        ];
    }
}
