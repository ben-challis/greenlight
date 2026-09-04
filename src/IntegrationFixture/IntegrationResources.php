<?php

declare(strict_types=1);

namespace Greenlight\IntegrationFixture;

use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * The integration fixtures visible to one worker channel.
 *
 * A worker receives shared data merged with only its own channel data.
 * This object does not contain data allocated only to other channels.
 * Fixture IDs must remain string keys in PHP maps.
 */
final readonly class IntegrationResources
{
    /**
     * @param array<non-empty-string, FixtureResource> $fixtures
     * @throws \InvalidArgumentException when a key or value is invalid
     */
    public function __construct(private array $fixtures = [])
    {
        $this->validate($fixtures);
    }

    /**
     * @param array<mixed, mixed> $fixtures
     */
    private function validate(array $fixtures): void
    {
        foreach ($fixtures as $id => $resource) {
            if (!\is_string($id) || $id === '' || \preg_match('//u', $id) !== 1 || !$resource instanceof FixtureResource) {
                throw new \InvalidArgumentException(
                    'Integration resources must be a map of non-empty UTF-8 fixture IDs to FixtureResource instances.',
                );
            }
        }
    }

    public static function empty(): self
    {
        return new self();
    }

    public function has(string $id): bool
    {
        return isset($this->fixtures[$id]);
    }

    /**
     * @throws \OutOfBoundsException when the fixture is not available
     */
    public function fixture(string $id): FixtureResource
    {
        return $this->fixtures[$id] ?? throw new \OutOfBoundsException(\sprintf(
            'No integration fixture named "%s" is available to this worker.',
            $id,
        ));
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $fixtures = [];

        foreach ($this->fixtures as $id => $resource) {
            $fixtures[$id] = $resource->toWire();
        }

        return ['fixtures' => $fixtures];
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $payload
     * @throws WireCommunicationFailed
     */
    public static function fromWire(array $payload): static
    {
        $raw = Wire::map($payload, 'fixtures');
        $fixtures = [];

        foreach ($raw as $id => $resource) {
            if ($id === '' || \preg_match('//u', $id) !== 1 || !\is_array($resource)) {
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
