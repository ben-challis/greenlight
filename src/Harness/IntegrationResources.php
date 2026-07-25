<?php

declare(strict_types=1);

namespace Greenlight\Harness;

use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * The integration fixtures visible to one worker channel.
 *
 * A worker receives shared data merged with only its own channel data; it
 * cannot inspect credentials allocated to another concurrently running lane.
 */
final readonly class IntegrationResources implements WireSerializable
{
    /**
     * @param array<non-empty-string, FixtureResource> $fixtures
     */
    public function __construct(private array $fixtures = [])
    {
        // The constructor's PHPDoc is the public static contract. Untrusted
        // wire data is validated by fromWire() before it reaches here.
    }

    public static function empty(): self
    {
        return new self();
    }

    public function has(string $id): bool
    {
        return isset($this->fixtures[$id]);
    }

    public function fixture(string $id): FixtureResource
    {
        return $this->fixtures[$id] ?? throw new \OutOfBoundsException(\sprintf(
            'No integration fixture named "%s" is available to this worker.',
            $id,
        ));
    }

    #[\Override]
    public function toWire(): array
    {
        $fixtures = [];

        foreach ($this->fixtures as $id => $resource) {
            $fixtures[$id] = $resource->toWire();
        }

        return ['fixtures' => $fixtures];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $raw = Wire::map($payload, 'fixtures');
        $fixtures = [];

        foreach ($raw as $id => $resource) {
            if ($id === '' || !\is_array($resource)) {
                throw InvalidWirePayload::wrongType('fixtures', 'a map of fixture resource payloads', $resource);
            }

            /** @var array<string, mixed> $resource */
            $fixtures[$id] = FixtureResource::fromWire($resource);
        }

        return new self($fixtures);
    }

    /**
     * @return array{fixtures: array<non-empty-string, FixtureResource>}
     */
    public function __debugInfo(): array
    {
        return ['fixtures' => $this->fixtures];
    }
}
