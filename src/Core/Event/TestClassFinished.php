<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Wire\Wire;

final readonly class TestClassFinished implements Event
{
    /**
     * @param non-empty-string $class
     * @param string $workerId the worker that ran the class; empty when the
     *   producer predates worker attribution
     */
    public function __construct(
        public string $class,
        public float $occurredAt,
        public string $workerId = '',
    ) {}

    #[\Override]
    public function toWire(): array
    {
        return [
            'class' => $this->class,
            'occurredAt' => $this->occurredAt,
            'workerId' => $this->workerId,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'class'),
            Wire::float($payload, 'occurredAt'),
            \array_key_exists('workerId', $payload) ? Wire::string($payload, 'workerId') : '',
        );
    }
}
