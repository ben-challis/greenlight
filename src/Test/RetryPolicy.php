<?php

declare(strict_types=1);

namespace Greenlight\Test;

use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * Defines the retry limit and the optional throwable filter for a test.
 */
final readonly class RetryPolicy
{
    /**
     * @var positive-int|null
     */
    public ?int $times;

    /**
     * @param non-empty-string|null $onlyOn
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        ?int $times = null,
        public ?string $onlyOn = null,
    ) {
        if ($times !== null && $times < 1) {
            throw new \InvalidArgumentException('Retry times must be at least 1.');
        }

        if ($onlyOn === '') {
            throw new \InvalidArgumentException('Retry throwable type must not be empty.');
        }

        $this->times = $times;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'times' => $this->times,
            'onlyOn' => $this->onlyOn,
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
        $times = Wire::nullableInt($payload, 'times');
        $onlyOn = Wire::nullableString($payload, 'onlyOn');

        if ($times !== null && $times < 1) {
            throw InvalidWirePayload::wrongType('times', 'an integer greater than zero or null', $times);
        }

        if ($onlyOn === '') {
            throw InvalidWirePayload::wrongType('onlyOn', 'a non-empty string or null', $onlyOn);
        }

        return new self($times, $onlyOn);
    }
}
