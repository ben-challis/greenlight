<?php

declare(strict_types=1);

namespace Greenlight\Event;

use Greenlight\Result\TestResult;
use Greenlight\Wire\Wire;

final readonly class TestFinished implements Event
{
    public function __construct(public TestResult $result, public float $occurredAt)
    {
        if (!\is_finite($occurredAt)) {
            throw new \InvalidArgumentException('Event timestamp MUST be finite.');
        }
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'result' => $this->result->toWire(),
            'occurredAt' => $this->occurredAt,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            TestResult::fromWire(Wire::map($payload, 'result')),
            Wire::float($payload, 'occurredAt'),
        );
    }
}
