<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Core\Result\ResultPolicy;
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
     * @param list<non-empty-string>|null $coverageInclude null means coverage is off
     * @param non-empty-string|null $coverageDriver
     * @param non-empty-string|null $configFile loaded worker-side to instantiate plugins; null runs plugin-free
     */
    public function __construct(
        public ExecutionPlan $slice,
        public ?int $recycleAfterTests = null,
        public ?int $recycleAboveMemoryBytes = null,
        public ?array $coverageInclude = null,
        public ?string $coverageDriver = null,
        public ?string $configFile = null,
        public bool $detectLeaks = false,
        public ?ResultPolicy $policy = null,
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
            'coverageInclude' => $this->coverageInclude,
            'coverageDriver' => $this->coverageDriver,
            'configFile' => $this->configFile,
            'detectLeaks' => $this->detectLeaks,
            'policy' => $this->policy?->toWire(),
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $recycleAfterTests = Wire::nullableInt($payload, 'recycleAfterTests');
        $recycleAboveMemory = Wire::nullableInt($payload, 'recycleAboveMemoryBytes');
        $coverageInclude = Wire::nullableListOfStrings($payload, 'coverageInclude');
        $coverageDriver = Wire::nullableString($payload, 'coverageDriver');

        if ($coverageInclude !== null) {
            $coverageInclude = \array_values(\array_filter($coverageInclude, static fn(string $path): bool => $path !== ''));
        }

        $configFile = Wire::nullableString($payload, 'configFile');

        return new self(
            ExecutionPlan::fromWire(Wire::map($payload, 'slice')),
            $recycleAfterTests === null ? null : \max(1, $recycleAfterTests),
            $recycleAboveMemory === null ? null : \max(1, $recycleAboveMemory),
            $coverageInclude,
            $coverageDriver === '' ? null : $coverageDriver,
            $configFile === '' ? null : $configFile,
            Wire::bool($payload, 'detectLeaks'),
            ($policy = Wire::nullableMap($payload, 'policy')) === null ? null : ResultPolicy::fromWire($policy),
        );
    }
}
