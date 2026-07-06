<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Core\Wire\Wire;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Runner\Protocol\Message;

/**
 * A plan slice for the worker to execute, with its recycling thresholds.
 *
 * @internal
 */
final readonly class Assign implements Message
{
    /**
     * @param positive-int|null $recycleAfterTests
     * @param positive-int|null $recycleAboveMemoryBytes
     */
    public function __construct(
        public ExecutionPlan $slice,
        public ?int $recycleAfterTests = null,
        public ?int $recycleAboveMemoryBytes = null,
    ) {}

    #[\Override]
    public static function tag(): string
    {
        return 'assign';
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'slice' => $this->slice->toWire(),
            'recycleAfterTests' => $this->recycleAfterTests,
            'recycleAboveMemoryBytes' => $this->recycleAboveMemoryBytes,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $recycleAfterTests = Wire::nullableInt($payload, 'recycleAfterTests');
        $recycleAboveMemory = Wire::nullableInt($payload, 'recycleAboveMemoryBytes');

        return new self(
            ExecutionPlan::fromWire(Wire::map($payload, 'slice')),
            $recycleAfterTests === null ? null : \max(1, $recycleAfterTests),
            $recycleAboveMemory === null ? null : \max(1, $recycleAboveMemory),
        );
    }
}
