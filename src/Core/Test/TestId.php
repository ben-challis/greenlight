<?php

declare(strict_types=1);

namespace Greenlight\Core\Test;

use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Identifies a test across processes for assignment, rerun selection, and the timing cache.
 */
final readonly class TestId implements WireSerializable, \Stringable
{
    /**
     * @var non-empty-string
     */
    public string $class;

    /**
     * @var non-empty-string
     */
    public string $method;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $class,
        string $method,
        public ?string $dataSetKey = null,
    ) {
        if ($class === '') {
            throw new \InvalidArgumentException('Test ID class must not be empty.');
        }

        if ($method === '') {
            throw new \InvalidArgumentException('Test ID method must not be empty.');
        }

        $this->class = $class;
        $this->method = $method;
    }

    public function equals(self $other): bool
    {
        return $this->class === $other->class
            && $this->method === $other->method
            && $this->dataSetKey === $other->dataSetKey;
    }

    #[\Override]
    public function __toString(): string
    {
        if ($this->dataSetKey === null) {
            return $this->class . '::' . $this->method;
        }

        return \sprintf('%s::%s[%s]', $this->class, $this->method, $this->dataSetKey);
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'class' => $this->class,
            'method' => $this->method,
            'dataSetKey' => $this->dataSetKey,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'class'),
            Wire::nonEmptyString($payload, 'method'),
            Wire::nullableString($payload, 'dataSetKey'),
        );
    }
}
