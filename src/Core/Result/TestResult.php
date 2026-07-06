<?php

declare(strict_types=1);

namespace Greenlight\Core\Result;

use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Immutable result of one test. Plugins never mutate a result; they produce a
 * replacement via withOutcome(), which records provenance.
 *
 * @internal
 */
final readonly class TestResult implements WireSerializable
{
    /**
     * @var positive-int
     */
    public int $attempts;

    /**
     * @param list<FailureDetail> $failures
     * @param list<OutcomeTransformation> $transformations
     */
    public function __construct(
        public TestId $id,
        public Outcome $outcome,
        public float $durationSeconds,
        public int $memoryDeltaBytes,
        int $attempts = 1,
        public array $failures = [],
        public ?ThrowableDetail $error = null,
        public ?string $skipReason = null,
        public array $transformations = [],
    ) {
        if ($durationSeconds < 0.0) {
            throw new \InvalidArgumentException('Duration cannot be negative.');
        }

        if ($attempts < 1) {
            throw new \InvalidArgumentException('Attempts must be at least 1.');
        }

        $this->attempts = $attempts;
    }

    /**
     * @param non-empty-string $transformedBy
     */
    public function withOutcome(Outcome $outcome, string $transformedBy): self
    {
        return new self(
            $this->id,
            $outcome,
            $this->durationSeconds,
            $this->memoryDeltaBytes,
            $this->attempts,
            $this->failures,
            $this->error,
            $this->skipReason,
            [...$this->transformations, new OutcomeTransformation($transformedBy, $this->outcome, $outcome)],
        );
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'id' => $this->id->toWire(),
            'outcome' => $this->outcome->value,
            'durationSeconds' => $this->durationSeconds,
            'memoryDeltaBytes' => $this->memoryDeltaBytes,
            'attempts' => $this->attempts,
            'failures' => \array_map(static fn(FailureDetail $failure): array => $failure->toWire(), $this->failures),
            'error' => $this->error?->toWire(),
            'skipReason' => $this->skipReason,
            'transformations' => \array_map(
                static fn(OutcomeTransformation $transformation): array => $transformation->toWire(),
                $this->transformations,
            ),
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $error = Wire::nullableMap($payload, 'error');

        return new self(
            TestId::fromWire(Wire::map($payload, 'id')),
            Outcome::from(Wire::nonEmptyString($payload, 'outcome')),
            \max(0.0, Wire::float($payload, 'durationSeconds')),
            Wire::int($payload, 'memoryDeltaBytes'),
            \max(1, Wire::int($payload, 'attempts')),
            \array_map(
                FailureDetail::fromWire(...),
                Wire::listOfMaps($payload, 'failures'),
            ),
            $error === null ? null : ThrowableDetail::fromWire($error),
            Wire::nullableString($payload, 'skipReason'),
            \array_map(
                OutcomeTransformation::fromWire(...),
                Wire::listOfMaps($payload, 'transformations'),
            ),
        );
    }
}
