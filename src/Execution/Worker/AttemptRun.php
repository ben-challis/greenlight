<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

use Greenlight\Artifact\Attachments;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Plugin\TestContext;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\Cleanup;

/**
 * Holds the state of one test attempt while its phases run.
 *
 * The first failure, error, or skip interrupts the test body. The first
 * teardown failure becomes the cause only if the attempt has no cause yet.
 * It then replaces a skip.
 *
 * @internal
 */
final class AttemptRun
{
    public ?\Throwable $cause = null;
    public ?ThrowableDetail $error = null;

    /** @var list<FailureDetail> */
    public array $failures = [];

    public ?string $skipReason = null;
    public ?TestContext $context = null;

    /** @var list<\Throwable> */
    public array $disposalFailures = [];

    public ?CapturedOutput $output = null;

    public function __construct(
        public readonly PlanEntry $entry,
        public readonly Attachments $attachments,
        public readonly Cleanup $cleanup,
        public readonly ?OutputCapture $capture,
        public readonly ?\Throwable $dataSetFailure,
    ) {}

    public function interrupted(): bool
    {
        return $this->cause instanceof \Throwable || $this->skipReason !== null;
    }

    public function skipped(string $reason): void
    {
        $this->skipReason = $reason;
    }

    public function errored(\Throwable $error): void
    {
        $this->cause = $error;
        $this->error = ThrowableDetail::fromThrowable($error);
    }

    public function failed(\Throwable $failure): void
    {
        if ($failure instanceof ExpectationFailed) {
            $this->cause = $failure;
            $this->failures = $failure->details;

            return;
        }

        $this->errored($failure);
    }

    /**
     * Records a teardown failure as the cause if the attempt has no cause.
     *
     * @return bool true if the failure became the cause
     */
    public function teardownFailed(\Throwable $failure): bool
    {
        if ($this->cause instanceof \Throwable) {
            return false;
        }

        $this->skipReason = null;
        $this->failed($failure);

        return true;
    }

    /** Adds a cleanup failure that is not the cause as secondary evidence. */
    public function secondaryCleanupFailure(\Throwable $failure): void
    {
        if ($failure instanceof ExpectationFailed) {
            $this->failures = [...$this->failures, ...$failure->details];

            return;
        }

        $this->failures[] = new FailureDetail(\sprintf(
            'Cleanup callback caused an error: %s',
            $failure->getMessage(),
        ));
    }

    public function outcome(): Outcome
    {
        return match (true) {
            $this->error instanceof ThrowableDetail => Outcome::Errored,
            $this->failures !== [] => Outcome::Failed,
            $this->skipReason !== null => Outcome::Skipped,
            default => Outcome::Passed,
        };
    }
}
