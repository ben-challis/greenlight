<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Core\Test\TestDefinition;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireCommunicationFailed;
use Greenlight\Core\Wire\WireSerializable;

/** @internal */
final readonly class PlanEntry implements WireSerializable
{
    public TestId $id;

    public function __construct(
        public TestDefinition $definition,
        public ?string $dataSetKey = null,
    ) {
        $this->id = new TestId($definition->class, $definition->method, $dataSetKey);
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'definition' => $this->definition->toWire(),
            'dataSetKey' => $this->dataSetKey,
        ];
    }

    /**
     * @throws WireCommunicationFailed
     */
    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            TestDefinition::fromWire(Wire::map($payload, 'definition')),
            Wire::nullableString($payload, 'dataSetKey'),
        );
    }
}
