<?php

declare(strict_types=1);

namespace Greenlight\Core\Test;

use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireCommunicationFailed;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Defines timeout, output capture, and expectation rules for one test.
 */
final readonly class ExecutionPolicy implements WireSerializable
{
    /** @throws \InvalidArgumentException */
    public function __construct(
        public ?float $timeoutSeconds = null,
        public bool $capture = true,
        public bool $noExpectations = false,
    ) {
        if (!self::isValidTimeout($timeoutSeconds)) {
            throw new \InvalidArgumentException('Timeout seconds must be finite and greater than zero.');
        }
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'timeoutSeconds' => $this->timeoutSeconds,
            'capture' => $this->capture,
            'noExpectations' => $this->noExpectations,
        ];
    }

    /** @throws WireCommunicationFailed */
    #[\Override]
    public static function fromWire(array $payload): static
    {
        $timeoutSeconds = Wire::nullableFloat($payload, 'timeoutSeconds');

        if (!self::isValidTimeout($timeoutSeconds)) {
            throw InvalidWirePayload::wrongType(
                'timeoutSeconds',
                'a finite float greater than zero or null',
                $timeoutSeconds,
            );
        }

        return new self(
            $timeoutSeconds,
            Wire::bool($payload, 'capture'),
            Wire::bool($payload, 'noExpectations'),
        );
    }

    private static function isValidTimeout(?float $seconds): bool
    {
        return $seconds === null || (\is_finite($seconds) && $seconds > 0.0);
    }
}
