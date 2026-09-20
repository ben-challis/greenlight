<?php

declare(strict_types=1);

namespace Greenlight\Event;

use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * Contains orchestrator-observed timing data for one worker process.
 *
 * A null phase duration means that the worker did not reach both phase
 * endpoints. Idle durations contain only states that the orchestrator can
 * distinguish.
 */
final readonly class WorkerTiming
{
    /** @var non-empty-string */
    public string $workerId;

    /** @var non-negative-int */
    public int $assignmentGaps;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $workerId,
        public ?float $spawnToHelloSeconds,
        public ?float $helloToReadySeconds,
        public ?float $readyToFirstAssignmentSeconds,
        int $assignmentGaps,
        public float $assignmentGapSeconds,
        public float $bootstrapBarrierSeconds,
        public float $resourceCapacitySeconds,
        public float $noQueuedWorkSeconds,
        public ?float $retirementToExitSeconds,
    ) {
        if ($workerId === '') {
            throw new \InvalidArgumentException('Worker ID cannot be empty.');
        }

        foreach ([
            $spawnToHelloSeconds,
            $helloToReadySeconds,
            $readyToFirstAssignmentSeconds,
            $assignmentGapSeconds,
            $bootstrapBarrierSeconds,
            $resourceCapacitySeconds,
            $noQueuedWorkSeconds,
            $retirementToExitSeconds,
        ] as $duration) {
            if ($duration !== null && (!\is_finite($duration) || $duration < 0.0)) {
                throw new \InvalidArgumentException('Use finite, nonnegative worker timing durations.');
            }
        }

        if ($assignmentGaps < 0) {
            throw new \InvalidArgumentException('Worker assignment gap count cannot be negative.');
        }

        $this->workerId = $workerId;
        $this->assignmentGaps = $assignmentGaps;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'workerId' => $this->workerId,
            'spawnToHelloSeconds' => $this->spawnToHelloSeconds,
            'helloToReadySeconds' => $this->helloToReadySeconds,
            'readyToFirstAssignmentSeconds' => $this->readyToFirstAssignmentSeconds,
            'assignmentGaps' => $this->assignmentGaps,
            'assignmentGapSeconds' => $this->assignmentGapSeconds,
            'bootstrapBarrierSeconds' => $this->bootstrapBarrierSeconds,
            'resourceCapacitySeconds' => $this->resourceCapacitySeconds,
            'noQueuedWorkSeconds' => $this->noQueuedWorkSeconds,
            'retirementToExitSeconds' => $this->retirementToExitSeconds,
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
        return new self(
            Wire::nonEmptyString($payload, 'workerId'),
            Wire::nullableFloat($payload, 'spawnToHelloSeconds'),
            Wire::nullableFloat($payload, 'helloToReadySeconds'),
            Wire::nullableFloat($payload, 'readyToFirstAssignmentSeconds'),
            Wire::int($payload, 'assignmentGaps'),
            Wire::float($payload, 'assignmentGapSeconds'),
            Wire::float($payload, 'bootstrapBarrierSeconds'),
            Wire::float($payload, 'resourceCapacitySeconds'),
            Wire::float($payload, 'noQueuedWorkSeconds'),
            Wire::nullableFloat($payload, 'retirementToExitSeconds'),
        );
    }
}
