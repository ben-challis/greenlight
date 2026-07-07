<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Wire\Wire;

final readonly class TestClassFinished implements Event
{
    /**
     * @param non-empty-string $class
     */
    public function __construct(
        public string $class,
        public float $occurredAt,
    ) {}

    #[\Override]
    public function toWire(): array
    {
        return [
            'class' => $this->class,
            'occurredAt' => $this->occurredAt,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'class'),
            Wire::float($payload, 'occurredAt'),
        );
    }
}
