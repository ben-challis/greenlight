<?php

declare(strict_types=1);

namespace Greenlight\Event;

use Greenlight\Internal\Wire\Wire;
use Greenlight\Test\TestId;

final readonly class TestStarted implements WireEvent
{
    /**
     * @throws \InvalidArgumentException if $occurredAt is not finite
     */
    public function __construct(public TestId $id, public float $occurredAt)
    {
        if (!\is_finite($occurredAt)) {
            throw new \InvalidArgumentException('Use a finite event timestamp.');
        }
    }

    /** @internal */
    #[\Override]
    public function toWire(): array
    {
        return [
            'id' => $this->id->toWire(),
            'occurredAt' => $this->occurredAt,
        ];
    }

    /** @internal */
    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            TestId::fromWire(Wire::map($payload, 'id')),
            Wire::float($payload, 'occurredAt'),
        );
    }
}
