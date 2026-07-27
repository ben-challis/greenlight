<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Core\Wire\Wire;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Runner\Artifact\ArtifactSession;
use Greenlight\Runner\Protocol\Message;

/**
 * Sends an execution-plan part and worker replacement limits to a worker.
 *
 * @internal
 */
final readonly class Assign implements Message
{
    /**
     * @param positive-int|null $recycleAfterTests
     * @param positive-int|null $recycleAboveMemoryBytes
     * @param list<non-empty-string>|null $coverageInclude Null disables coverage.
     * @param non-empty-string|null $coverageDriver
     * @param list<non-empty-string> $machineResourceLeases Machine resource
     *   coordination keys for this assignment.
     */
    public function __construct(
        public ExecutionPlan $slice,
        public ?int $recycleAfterTests = null,
        public ?int $recycleAboveMemoryBytes = null,
        public ?array $coverageInclude = null,
        public ?string $coverageDriver = null,
        public bool $detectLeaks = false,
        public ?ResultPolicy $policy = null,
        public ?ArtifactSession $artifactSession = null,
        public ?ArtifactConfiguration $artifactConfiguration = null,
        public array $machineResourceLeases = [],
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
            'detectLeaks' => $this->detectLeaks,
            'policy' => $this->policy?->toWire(),
            'artifactSession' => $this->artifactSession?->toWire(),
            'artifactConfiguration' => $this->artifactConfiguration?->toWire(),
            'machineResourceLeases' => $this->machineResourceLeases,
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

        return new self(
            ExecutionPlan::fromWire(Wire::map($payload, 'slice')),
            $recycleAfterTests === null ? null : \max(1, $recycleAfterTests),
            $recycleAboveMemory === null ? null : \max(1, $recycleAboveMemory),
            $coverageInclude,
            $coverageDriver === '' ? null : $coverageDriver,
            Wire::bool($payload, 'detectLeaks'),
            ($policy = Wire::nullableMap($payload, 'policy')) === null ? null : ResultPolicy::fromWire($policy),
            ($artifacts = \array_key_exists('artifactSession', $payload) ? Wire::nullableMap($payload, 'artifactSession') : null) === null
                ? null
                : ArtifactSession::fromWire($artifacts),
            ($artifactConfiguration = \array_key_exists('artifactConfiguration', $payload) ? Wire::nullableMap($payload, 'artifactConfiguration') : null) === null
                ? null
                : ArtifactConfiguration::fromWire($artifactConfiguration),
            \array_key_exists('machineResourceLeases', $payload)
                ? \array_values(\array_filter(
                    Wire::listOfStrings($payload, 'machineResourceLeases'),
                    static fn(string $key): bool => $key !== '',
                ))
                : [],
        );
    }
}
