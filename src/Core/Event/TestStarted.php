<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\Wire;

final readonly class TestStarted implements Event
{
    public function __construct(public TestId $id, public float $occurredAt)
    {
        if (!\is_finite($occurredAt)) {
            throw new \InvalidArgumentException('Event timestamp MUST be finite.');
        }
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'id' => $this->id->toWire(),
            'occurredAt' => $this->occurredAt,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            TestId::fromWire(Wire::map($payload, 'id')),
            Wire::float($payload, 'occurredAt'),
        );
    }
}
