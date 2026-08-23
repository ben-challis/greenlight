<?php

declare(strict_types=1);

namespace Greenlight\Test;

use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * Defines worker assignment and resource rules for one test.
 */
final readonly class SchedulingPolicy
{
    /**
     * @var list<non-empty-string>
     */
    public array $resources;

    /**
     * @param list<string> $resources named resources required by this test
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public bool $isolated = false,
        array $resources = [],
        public bool $allowParallel = false,
    ) {
        $validated = [];

        foreach ($resources as $resource) {
            ResourceName::assertValid($resource);
            $validated[$resource] = $resource;
        }

        $this->resources = \array_values($validated);
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'isolated' => $this->isolated,
            'resources' => $this->resources,
            'allowParallel' => $this->allowParallel,
        ];
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $payload
     * @throws WireCommunicationFailed
     */
    public static function fromWire(array $payload): static
    {
        $resources = Wire::listOfStrings($payload, 'resources');

        foreach ($resources as $resource) {
            if (!ResourceName::isValid($resource)) {
                throw InvalidWirePayload::wrongType('resources', 'a list of canonical resource names', $resource);
            }
        }

        return new self(
            Wire::bool($payload, 'isolated'),
            $resources,
            Wire::bool($payload, 'allowParallel'),
        );
    }
}
