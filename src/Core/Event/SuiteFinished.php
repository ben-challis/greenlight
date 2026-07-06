<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

use Greenlight\Core\Wire\Wire;

/**
 * @internal
 */
final readonly class SuiteFinished implements Event
{
    /**
     * @param non-empty-string $suite
     */
    public function __construct(
        public string $suite,
        public float $occurredAt,
    ) {}

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
