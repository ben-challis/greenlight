<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\Wire;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Runner\Protocol\Message;

/**
 * Worker to orchestrator: a recycle threshold was hit; the listed entries
 * remain unexecuted and need reassignment. The worker exits after sending.
 *
 * @internal
 */
final readonly class Recycling implements Message
{
    /**
     * @param list<TestId> $remaining
     */
    public function __construct(
        public RecycleReason $reason,
        public array $remaining,
        public ?CoverageMap $coverage = null,
    ) {}

    #[\Override]
    public static function tag(): string
    {
        return 'recycling';
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'reason' => $this->reason->value,
            'remaining' => \array_map(static fn(TestId $id): array => $id->toWire(), $this->remaining),
            'coverage' => $this->coverage?->toWire(),
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $coverage = Wire::nullableMap($payload, 'coverage');

        return new self(
            RecycleReason::from(Wire::nonEmptyString($payload, 'reason')),
            \array_map(TestId::fromWire(...), Wire::listOfMaps($payload, 'remaining')),
            $coverage === null ? null : CoverageMap::fromWire($coverage),
        );
    }
}
