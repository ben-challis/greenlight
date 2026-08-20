<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Wire\Wire;

final readonly class TestClassStarted implements Event
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
        public bool $isolated = false,
    ) {
        if ($class === '') {
            throw new \InvalidArgumentException('Test class name MUST NOT be empty.');
        }

        if (!\is_finite($occurredAt)) {
            throw new \InvalidArgumentException('Event timestamp MUST be finite.');
        }

        $this->class = $class;
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'class' => $this->class,
            'occurredAt' => $this->occurredAt,
            'workerId' => $this->workerId,
            ...($this->isolated ? ['isolated' => true] : []),
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'class'),
            Wire::float($payload, 'occurredAt'),
            \array_key_exists('workerId', $payload) ? Wire::string($payload, 'workerId') : '',
            \array_key_exists('isolated', $payload) && Wire::bool($payload, 'isolated'),
        );
    }
}
