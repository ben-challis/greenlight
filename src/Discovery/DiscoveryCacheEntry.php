<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

/**
 * One file's record in the discovery cache: the mtime and size fingerprint
 * the file was cached under, plus the wire payloads of every plan entry it
 * declared.
 *
 * fromDecoded() rebuilds a record from untrusted decoded JSON and returns
 * null when any part of the shape is malformed, so a corrupt cache degrades
 * to a re-parse instead of an error. jsonSerialize() emits the same shape
 * fromDecoded() accepts.
 *
 * @internal
 */
final readonly class DiscoveryCacheEntry implements \JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $entries
     */
    public function __construct(
        public int $mtime,
        public int $size,
        public array $entries,
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

        return new self($decoded['mtime'], $decoded['size'], $payloads);
    }

    /**
     * @return array{mtime: int, size: int, entries: list<array<string, mixed>>}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'mtime' => $this->mtime,
            'size' => $this->size,
            'entries' => $this->entries,
        ];
    }
}
