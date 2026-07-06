<?php

declare(strict_types=1);

namespace Greenlight\Core\Test;

use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Stable identity of a single runnable test: class, method, and the data-set
 * key when the method is expanded from a #[DataSet] provider. Identical across
 * processes and runs for the same code state; used for distribution, rerun
 * selection, and timing caches.
 *
 * @internal
 */
final readonly class TestId implements WireSerializable, \Stringable
{
    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     */
    public function __construct(
        public string $class,
        public string $method,
        public ?string $dataSetKey = null,
    ) {}

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
