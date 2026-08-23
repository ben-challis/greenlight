<?php

declare(strict_types=1);

namespace Greenlight\Discovery\Plan;

use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestId;

/**
 * Contains one test definition and its optional data-set key in an execution plan.
 *
 * @internal
 */
final readonly class PlanEntry
{
    public TestId $id;

    public function __construct(
        public TestDefinition $definition,
        public ?string $dataSetKey = null,
    ) {
        $this->id = new TestId($definition->class, $definition->method, $dataSetKey);
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        return [
            'definition' => $this->definition->toWire(),
            'dataSetKey' => $this->dataSetKey,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @throws WireCommunicationFailed
     */
    public static function fromWire(array $payload): static
    {
        return new self(
            TestDefinition::fromWire(Wire::map($payload, 'definition')),
            Wire::nullableString($payload, 'dataSetKey'),
        );
    }
}
