<?php

declare(strict_types=1);

namespace Greenlight\Event;

use Greenlight\Internal\Wire\Wire;
use Greenlight\Result\TestResult;

final readonly class TestFinished implements WireEvent
{
    /**
     * @throws \InvalidArgumentException if $occurredAt is not finite
     */
    public function __construct(public TestResult $result, public float $occurredAt)
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
            'result' => $this->result->toWire(),
            'occurredAt' => $this->occurredAt,
        ];
    }

    /** @internal */
    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            TestResult::fromWire(Wire::map($payload, 'result')),
            Wire::float($payload, 'occurredAt'),
        );
    }
}
