<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\SourceLocation;
use Greenlight\Core\Test\ExpectationCounter;

/**
 * Polls the probe until its matcher passes or the deadline expires.
 *
 * @template T
 *
 * @extends TemporalExpectation<T>
 */
final class EventuallyExpectation extends TemporalExpectation
{
    /**
     * @param \Closure(): T $probe
     * @param list<class-string<\Exception>> $retryOnExceptions
     * @param list<ExpectationExtension> $extensions
     */
    public function __construct(
        \Closure $probe,
        PollingClock $clock,
        ?float $attemptDeadline,
        float $intervalSeconds,
        private readonly float $withinSeconds,
        private readonly array $retryOnExceptions,
        ValueRenderer $renderer,
        array $extensions,
    ) {
        parent::__construct(
            $probe,
            $clock,
            $attemptDeadline,
            $intervalSeconds,
            $renderer,
            $extensions,
        );
    }

    #[\Override]
    protected function waitFor(
        \Closure $matcher,
        bool $negated,
        ?SourceLocation $location,
    ): Expectation {
        $startedAt = $this->clock->now();
        $requestedDeadline = $startedAt + $this->withinSeconds;
        $deadline = $this->attemptDeadline === null
            ? $requestedDeadline
            : \min($requestedDeadline, $this->attemptDeadline);
        $truncatedByTest = $deadline < $requestedDeadline;
        $observations = new ObservationLog($startedAt);
        $last = null;
        $lastFailure = null;
        $counted = false;

        if ($deadline <= $startedAt) {
            ExpectationCounter::increment();
            $this->failure(
                \sprintf(
                    'No time remains for the requested %.3f-second eventually() wait.',
                    $this->withinSeconds,
                ),
                $observations,
                null,
                null,
                $location,
            );
        }

        while (true) {
            $last = $this->observe($matcher, $negated, $this->retryOnExceptions);
            $observedAt = $this->clock->now();
            $observations->record($observedAt, $last->rendered);

            if (!$counted) {
                ExpectationCounter::increment();
                $counted = true;
            }

            if ($last->matched && $observedAt <= $deadline) {
                return $this->immediate($last->subject);
            }

            if ($last->failure instanceof FailureDetail) {
                $lastFailure = $last->failure;
            }

            if ($observedAt >= $deadline) {
                $summary = $truncatedByTest
                    ? \sprintf(
                        'The test time limit stopped the eventually() expectation after %d observations. The requested wait was %.3f seconds.',
                        $observations->count(),
                        $this->withinSeconds,
                    )
                    : \sprintf(
                        'The eventually() expectation did not pass within %.3f seconds after %d observations.',
                        $this->withinSeconds,
                        $observations->count(),
                    );
                $this->failure($summary, $observations, $last, $lastFailure, $location);
            }

            $this->sleepUntil(\min($observedAt + $this->intervalSeconds, $deadline));
        }
    }
}
