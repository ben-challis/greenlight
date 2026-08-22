<?php

declare(strict_types=1);

namespace Greenlight\Result;

use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * Expected and actual are strings that the worker renders. Live values never
 * cross the process boundary.
 */
final readonly class FailureDetail
{
    /**
     * @var non-empty-string
     */
    public string $message;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $message,
        public ?string $expected = null,
        public ?string $actual = null,
        public ?SourceLocation $location = null,
    ) {
        if ($message === '') {
            throw new \InvalidArgumentException('Failure detail message must not be empty.');
        }

        $this->message = $message;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'message' => $this->message,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'location' => $this->location?->toWire(),
        ];
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $payload
     * @throws WireCommunicationFailed
     */
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
