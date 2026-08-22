<?php

declare(strict_types=1);

namespace Greenlight\Core\Test;

use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireCommunicationFailed;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Defines the retry limit and the optional throwable filter for a test.
 */
final readonly class RetryPolicy implements WireSerializable
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

    #[\Override]
    public function toWire(): array
    {
        return [
            'times' => $this->times,
            'onlyOn' => $this->onlyOn,
        ];
    }

    /** @throws WireCommunicationFailed */
    #[\Override]
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
