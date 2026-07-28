<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Wire\Wire;

final readonly class SuiteStarted implements Event
{
    /**
     * @var non-empty-string
     */
    public string $suite;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(string $suite, public float $occurredAt)
    {
        if ($suite === '') {
            throw new \InvalidArgumentException('Suite name MUST NOT be empty.');
        }

        $this->suite = $suite;
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'suite' => $this->suite,
            'occurredAt' => $this->occurredAt,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'suite'),
            Wire::float($payload, 'occurredAt'),
        );
    }
}
