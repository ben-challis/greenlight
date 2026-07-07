<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Wire\Wire;

final readonly class TestFinished implements Event
{
    public function __construct(
        public TestResult $result,
        public float $occurredAt,
    ) {}

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
