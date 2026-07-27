<?php

declare(strict_types=1);

namespace Greenlight\Core\Result;

use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\StagedAttachment;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * A plugin does not change a result object. It uses `withOutcome()` to produce
 * a replacement and record the source of the change.
 *
 * The expectations value counts each matcher in a chain separately. It counts
 * each mock expectation when disposal verifies it. Stubs do not add to the
 * count. An unsuccessful result contains the count at the time of the abort.
 * Old wire payloads without this field decode to zero.
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
     * @param non-negative-int $expectations
     * @param list<Attachment> $attachments
     *
     * @throws \InvalidArgumentException
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
        public ?CapturedOutput $output = null,
        public bool $risky = false,
        public int $expectations = 0,
        public array $attachments = [],
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
        return $this->with(
            outcome: $outcome,
            transformations: [...$this->transformations, new OutcomeTransformation($transformedBy, $this->outcome, $outcome)],
        );
    }

    /**
     * Adds failure details and records the outcome transformation.
     *
     * @internal
     *
     * @param non-empty-string $transformedBy
     * @param non-empty-list<FailureDetail> $details
     */
    public function failedBy(string $transformedBy, array $details): self
    {
        return $this->with(
            outcome: Outcome::Failed,
            failures: [...$this->failures, ...$details],
            transformations: [...$this->transformations, new OutcomeTransformation($transformedBy, $this->outcome, Outcome::Failed)],
        );
    }

    /** @internal */
    public function asRisky(): self
    {
        return $this->with(risky: true);
    }

    /**
     * @internal
     *
     * @param list<Attachment> $attachments
     */
    public function withAttachments(array $attachments): self
    {
        return $this->with(attachments: $attachments);
    }

    /**
     * Returns the same result with a recovered attempt count.
     *
     * @internal
     */
    public function withAttempts(int $attempts): self
    {
        return $this->with(attempts: $attempts);
    }

    /**
     * Returns the same result with an error and the specified detail.
     *
     * @internal
     */
    public function erroredBy(ThrowableDetail $error): self
    {
        return $this->with(outcome: Outcome::Errored, error: $error);
    }

    /**
     * @internal
     *
     * @param list<FailureDetail> $failures
     */
    public function withFailures(array $failures): self
    {
        return $this->with(failures: $failures);
    }

    /**
     * Copies each field that the derived result does not replace. New fields
     * only require an entry here.
     *
     * @param list<FailureDetail>|null $failures
     * @param list<OutcomeTransformation>|null $transformations
     * @param list<Attachment>|null $attachments
     */
    private function with(
        ?Outcome $outcome = null,
        ?array $failures = null,
        ?array $transformations = null,
        ?bool $risky = null,
        ?ThrowableDetail $error = null,
        ?array $attachments = null,
        ?int $attempts = null,
    ): self {
        return new self(
            $this->id,
            $outcome ?? $this->outcome,
            $this->durationSeconds,
            $this->memoryDeltaBytes,
            $attempts ?? $this->attempts,
            $failures ?? $this->failures,
            $error ?? $this->error,
            $this->skipReason,
            $transformations ?? $this->transformations,
            $this->output,
            $risky ?? $this->risky,
            $this->expectations,
            $attachments ?? $this->attachments,
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
            'output' => $this->output?->toWire(),
            'risky' => $this->risky,
            'expectations' => $this->expectations,
            'attachments' => \array_map(
                static fn(Attachment $attachment): array => $attachment->toWire(),
                $this->attachments,
            ),
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $error = Wire::nullableMap($payload, 'error');
        $output = Wire::nullableMap($payload, 'output');

        return new self(
            TestId::fromWire(Wire::map($payload, 'id')),
            Wire::enum($payload, 'outcome', Outcome::class),
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
            $output === null ? null : CapturedOutput::fromWire($output),
            \array_key_exists('risky', $payload) && Wire::bool($payload, 'risky'),
            \array_key_exists('expectations', $payload) ? \max(0, Wire::int($payload, 'expectations')) : 0,
            \array_map(
                static fn(array $attachment): Attachment => \array_key_exists('storageKey', $attachment)
                    ? StagedAttachment::fromWire($attachment)
                    : Attachment::fromWire($attachment),
                \array_key_exists('attachments', $payload) ? Wire::listOfMaps($payload, 'attachments') : [],
            ),
        );
    }
}
