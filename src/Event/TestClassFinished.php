<?php

declare(strict_types=1);

namespace Greenlight\Event;

use Greenlight\Internal\Wire\Wire;

final readonly class TestClassFinished implements WireEvent
{
    /**
     * @var non-empty-string
     */
    public string $class;

    /**
     * @param string $workerId The worker that ran the class, or an empty
     *   string from a producer without worker attribution
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $class,
        public float $occurredAt,
        public string $workerId = '',
    ) {
        if ($class === '') {
            throw new \InvalidArgumentException('Test class name cannot be empty.');
        }

        if (!\is_finite($occurredAt)) {
            throw new \InvalidArgumentException('Use a finite event timestamp.');
        }

        $this->class = $class;
    }

    /** @internal */
    #[\Override]
    public function toWire(): array
    {
        return [
            'class' => $this->class,
            'occurredAt' => $this->occurredAt,
            'workerId' => $this->workerId,
        ];
    }

    /** @internal */
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
