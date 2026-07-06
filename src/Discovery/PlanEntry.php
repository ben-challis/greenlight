<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * One runnable unit in an execution plan: the stable identity of the test
 * plus everything discovery learned about the method it belongs to.
 *
 * @internal
 */
final readonly class PlanEntry implements WireSerializable
{
    /**
     * @throws \InvalidArgumentException when the id does not match the metadata
     */
    public function __construct(
        public TestId $id,
        public TestMetadata $metadata,
    ) {
        if ($id->class !== $metadata->class || $id->method !== $metadata->method) {
            throw new \InvalidArgumentException(\sprintf(
                'Plan entry identity %s::%s does not match its metadata %s::%s.',
                $id->class,
                $id->method,
                $metadata->class,
                $metadata->method,
            ));
        }
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'id' => $this->id->toWire(),
            'metadata' => $this->metadata->toWire(),
        ];
    }

    /**
     * @throws \InvalidArgumentException when the id does not match the metadata
     */
    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            TestId::fromWire(Wire::map($payload, 'id')),
            TestMetadata::fromWire(Wire::map($payload, 'metadata')),
        );
    }
}
