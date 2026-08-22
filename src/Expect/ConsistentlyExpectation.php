<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Result\SourceLocation;
use Greenlight\Core\Test\ExpectationCounter;

/**
 * Checks each probe value for a fixed period and fails on the first mismatch.
 * Use `Expect::consistently()` and `for()` to create this object.
 *
 * @template T
 *
 * @extends TemporalExpectation<T>
 */
final class ConsistentlyExpectation extends TemporalExpectation
{
    /**
     * @param \Closure(): T $probe
     * @param list<ExpectationExtension> $extensions
     */
    private function __construct(
        \Closure $probe,
        PollingClock $clock,
        ?float $attemptDeadline,
        float $intervalSeconds,
        private readonly float $forSeconds,
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

    /**
     * @internal Use Expect::consistently() and for() instead.
     *
     * @template TProbe
     *
     * @param \Closure(): TProbe $probe
     * @param list<ExpectationExtension> $extensions
     *
     * @return self<TProbe>
     */
    public static function create(
        \Closure $probe,
        PollingClock $clock,
        ?float $attemptDeadline,
        float $intervalSeconds,
        float $forSeconds,
        ValueRenderer $renderer,
        array $extensions,
    ): self {
        return new self(
            $probe,
            $clock,
            $attemptDeadline,
            $intervalSeconds,
            $forSeconds,
            $renderer,
            $extensions,
        );
    }

    /**
     * @param \Closure(Expectation<T>): Expectation<T> $matcher
     * @param non-empty-string|null $reason
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    #[\Override]
    protected function waitFor(
        \Closure $matcher,
        bool $negated,
        ?string $reason,
        ?SourceLocation $location,
    ): Expectation {
        $startedAt = $this->clock->now();
        $observations = new ObservationLog($startedAt);
        $last = $this->observe($matcher, $negated, $reason);
        $observedAt = $this->clock->now();
        $observations->record($observedAt, $last->rendered);
        ExpectationCounter::increment();

        if (!$last->matched) {
            $this->failure(
                'The consistently() expectation failed on the first observation.',
                $observations,
                $last,
                $last->failure,
                $location,
            );
        }

        $stableStartedAt = $observedAt;
        $requestedDeadline = $stableStartedAt + $this->forSeconds;
        $deadline = $this->attemptDeadline === null
            ? $requestedDeadline
            : \min($requestedDeadline, $this->attemptDeadline);
        $truncatedByTest = $deadline < $requestedDeadline;

        if ($deadline <= $observedAt) {
            $this->failure(
                \sprintf(
                    'No time remains for the requested %.3f-second consistently() observation period.',
                    $this->forSeconds,
                ),
                $observations,
                $last,
                null,
                $location,
            );
        }

        while (true) {
            $this->sleepUntil(\min($this->clock->now() + $this->intervalSeconds, $deadline));
            $last = $this->observe($matcher, $negated, $reason);
            $observedAt = $this->clock->now();
            $observations->record($observedAt, $last->rendered);

            if (!$last->matched) {
                $this->failure(
                    \sprintf(
                        'The consistently() expectation failed after %.3f seconds and %d observations.',
                        \max(0.0, $observedAt - $stableStartedAt),
                        $observations->count(),
                    ),
                    $observations,
                    $last,
                    $last->failure,
                    $location,
                );
            }

            if ($observedAt >= $deadline) {
                if ($truncatedByTest) {
                    $this->failure(
                        \sprintf(
                            'The test time limit ended the consistently() expectation early. The requested observation period was %.3f seconds.',
                            $this->forSeconds,
                        ),
                        $observations,
                        $last,
                        null,
                        $location,
                    );
                }

                if (!$last instanceof TemporalValueObservation) {
                    throw new \LogicException('A matched temporal observation must contain a subject.');
                }

                return $this->immediate($last->subject);
            }
        }
    }
}
