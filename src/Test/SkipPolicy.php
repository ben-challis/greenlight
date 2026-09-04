<?php

declare(strict_types=1);

namespace Greenlight\Test;

use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * Defines the static reason or condition that skips a test.
 */
final readonly class SkipPolicy
{
    /**
     * @var list<scalar|null>
     */
    public array $arguments;

    /**
     * @param non-empty-string|null $reason
     * @param non-empty-string|null $condition
     * @param list<mixed> $arguments validated to scalars or null
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public ?string $reason = null,
        public ?string $condition = null,
        array $arguments = [],
    ) {
        if ($reason === '') {
            throw new \InvalidArgumentException('Skip reason must not be empty.');
        }

        if ($condition === '') {
            throw new \InvalidArgumentException('Skip condition must not be empty.');
        }

        $validated = [];

        foreach ($arguments as $argument) {
            if ($argument !== null && !\is_scalar($argument)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Skip condition arguments must be scalars or null, got %s.',
                    \get_debug_type($argument),
                ));
            }

            if (\is_float($argument) && !\is_finite($argument)) {
                throw new \InvalidArgumentException('Use finite floats in skip condition arguments.');
            }

            $validated[] = $argument;
        }

        $this->arguments = $validated;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'reason' => $this->reason,
            'condition' => $this->condition,
            'arguments' => $this->arguments,
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
        $reason = Wire::nullableString($payload, 'reason');
        $condition = Wire::nullableString($payload, 'condition');

        if ($reason === '') {
            throw InvalidWirePayload::wrongType('reason', 'a non-empty string or null', $reason);
        }

        if ($condition === '') {
            throw InvalidWirePayload::wrongType('condition', 'a non-empty string or null', $condition);
        }

        $value = $payload['arguments'] ?? null;

        if (!\is_array($value) || !\array_is_list($value)) {
            throw InvalidWirePayload::wrongType('arguments', 'a list of scalars or nulls', $value);
        }

        $arguments = [];

        foreach ($value as $argument) {
            if ($argument !== null && !\is_scalar($argument)) {
                throw InvalidWirePayload::wrongType('arguments', 'a list of scalars or nulls', $argument);
            }

            if (\is_float($argument) && !\is_finite($argument)) {
                throw InvalidWirePayload::wrongType('arguments', 'a list of scalars or nulls with finite floats', $argument);
            }

            $arguments[] = $argument;
        }

        return new self($reason, $condition, $arguments);
    }
}
