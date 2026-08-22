<?php

declare(strict_types=1);

namespace Greenlight\Test;

use Greenlight\Wire\InvalidWirePayload;
use Greenlight\Wire\Wire;
use Greenlight\Wire\WireCommunicationFailed;
use Greenlight\Wire\WireSerializable;

/**
 * Identifies the method and optional external class that supply test data sets.
 */
final readonly class DataProvider implements WireSerializable
{
    /**
     * @param non-empty-string|null $method
     * @param non-empty-string|null $class
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public ?string $method = null,
        public ?string $class = null,
    ) {
        if ($method === '') {
            throw new \InvalidArgumentException('Data provider method must not be empty.');
        }

        if ($class === '') {
            throw new \InvalidArgumentException('Data provider class must not be empty.');
        }

        if ($class !== null && $method === null) {
            throw new \InvalidArgumentException('A data provider class requires a data provider method.');
        }
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'method' => $this->method,
            'class' => $this->class,
        ];
    }

    /** @throws WireCommunicationFailed */
    #[\Override]
    public static function fromWire(array $payload): static
    {
        $method = Wire::nullableString($payload, 'method');
        $class = Wire::nullableString($payload, 'class');

        if ($method === '') {
            throw InvalidWirePayload::wrongType('method', 'a non-empty string or null', $method);
        }

        if ($class === '') {
            throw InvalidWirePayload::wrongType('class', 'a non-empty string or null', $class);
        }

        if ($class !== null && $method === null) {
            throw InvalidWirePayload::wrongType('class', 'null when method is null', $class);
        }

        return new self($method, $class);
    }
}
