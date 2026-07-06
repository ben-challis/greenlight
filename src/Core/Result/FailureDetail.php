<?php

declare(strict_types=1);

namespace Greenlight\Core\Result;

use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * One expectation failure. Expected and actual are pre-rendered strings:
 * rendering happens worker-side in Expect, and live values never cross the
 * process boundary (RFC-001).
 */
final readonly class FailureDetail implements WireSerializable
{
    /**
     * @param non-empty-string $message
     */
    public function __construct(
        public string $message,
        public ?string $expected = null,
        public ?string $actual = null,
        public ?SourceLocation $location = null,
    ) {}

    #[\Override]
    public function toWire(): array
    {
        return [
            'message' => $this->message,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'location' => $this->location?->toWire(),
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $location = Wire::nullableMap($payload, 'location');

        return new self(
            Wire::nonEmptyString($payload, 'message'),
            Wire::nullableString($payload, 'expected'),
            Wire::nullableString($payload, 'actual'),
            $location === null ? null : SourceLocation::fromWire($location),
        );
    }
}
