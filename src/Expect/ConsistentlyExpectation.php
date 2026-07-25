<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Result\SourceLocation;
use Greenlight\Core\Test\ExpectationCounter;

/**
 * Applies one matcher repeatedly and fails on its first violation.
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
    public function __construct(
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

    #[\Override]
    protected function waitFor(
        \Closure $matcher,
        bool $negated,
        ?SourceLocation $location,
    ): Expectation {
        $startedAt = $this->clock->now();
        $observations = new ObservationLog($startedAt);
        $last = $this->observe($matcher, $negated);
        $observedAt = $this->clock->now();
        $observations->record($observedAt, $last->rendered);
        ExpectationCounter::increment();

        if (!$last->matched) {
            $this->failure(
                'Consistently expectation failed on its first observation.',
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
                    'The test timeout expired before the consistently expectation could observe for %.3fs.',
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
            $last = $this->observe($matcher, $negated);
            $observedAt = $this->clock->now();
            $observations->record($observedAt, $last->rendered);

            if (!$last->matched) {
                $this->failure(
                    \sprintf(
                        'Consistently expectation stopped passing after %.3fs and %d observations.',
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
                            'The test timeout expired before the consistently expectation could complete its requested %.3fs observation period.',
                            $this->forSeconds,
                        ),
                        $observations,
                        $last,
                        null,
                        $location,
                    );
                }

                return $this->immediate($last->subject);
            }
        }
    }
}
