<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Result\FailureDetail;
use Greenlight\Result\SourceLocation;
use Greenlight\Test\ExpectationCounter;

/**
 * Polls the probe until its matcher passes or the deadline expires.
 * Use `Expect::eventually()` and `within()` to create this object.
 *
 * @template T
 *
 * @extends TemporalExpectation<T>
 */
final class EventuallyExpectation extends TemporalExpectation
{
    /**
     * @internal Greenlight constructs temporal expectations.
     *
     * @param \Closure(): T $probe
     * @param list<class-string<\Exception>> $retryOnExceptions
     * @param list<ExpectationExtension> $extensions
     */
    private function __construct(
        \Closure $probe,
        PollingClock $clock,
        float $intervalSeconds,
        private readonly float $withinSeconds,
        private readonly array $retryOnExceptions,
        ValueRenderer $renderer,
        array $extensions,
    ) {
        parent::__construct(
            $probe,
            $clock,
            $intervalSeconds,
            $renderer,
            $extensions,
        );
    }

    /**
     * @internal Use Expect::eventually() and within() instead.
     *
     * @template TProbe
     *
     * @param \Closure(): TProbe $probe
     * @param list<class-string<\Exception>> $retryOnExceptions
     * @param list<ExpectationExtension> $extensions
     *
     * @return self<TProbe>
     */
    public static function create(
        \Closure $probe,
        PollingClock $clock,
        float $intervalSeconds,
        float $withinSeconds,
        array $retryOnExceptions,
        ValueRenderer $renderer,
        array $extensions,
    ): self {
        return new self(
            $probe,
            $clock,
            $intervalSeconds,
            $withinSeconds,
            $retryOnExceptions,
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
        $deadline = TemporalDeadline::forWait($startedAt + $this->withinSeconds);
        $observations = new ObservationLog($startedAt);
        $last = null;
        $lastFailure = null;
        $counted = false;

        if ($deadline->time <= $startedAt) {
            ExpectationCounter::increment();
            $this->failure(
                \sprintf(
                    $deadline->source === TemporalDeadlineSource::Enclosing
                        ? 'The enclosing expectation time limit left no time for the requested %.3f-second eventually() wait.'
                        : 'No time remains for the requested %.3f-second eventually() wait.',
                    $this->withinSeconds,
                ),
                $observations,
                null,
                null,
                $location,
            );
        }

        while (true) {
            $last = ExpectationRuntime::withDeadline(
                $deadline->time,
                fn(): TemporalObservation => $this->observe($matcher, $negated, $reason, $this->retryOnExceptions),
            );
            $observedAt = $this->clock->now();
            $observations->record($observedAt, $last->rendered);

            if (!$counted) {
                ExpectationCounter::increment();
                $counted = true;
            }

            if ($last->matched && $observedAt <= $deadline->time) {
                if (!$last instanceof TemporalValueObservation) {
                    throw new \LogicException('A matched temporal observation must contain a subject.');
                }

                return $this->immediate($last->subject);
            }

            if ($last->failure instanceof FailureDetail) {
                $lastFailure = $last->failure;
            }

            if ($observedAt >= $deadline->time) {
                $summary = match ($deadline->source) {
                    TemporalDeadlineSource::Test => \sprintf(
                        'The test time limit stopped the eventually() expectation after %d observations. The requested wait was %.3f seconds.',
                        $observations->count(),
                        $this->withinSeconds,
                    ),
                    TemporalDeadlineSource::Enclosing => \sprintf(
                        'The enclosing expectation time limit stopped the eventually() expectation after %d observations. The requested wait was %.3f seconds.',
                        $observations->count(),
                        $this->withinSeconds,
                    ),
                    TemporalDeadlineSource::Local => \sprintf(
                        'The eventually() expectation did not pass within %.3f seconds after %d observations.',
                        $this->withinSeconds,
                        $observations->count(),
                    ),
                };
                $this->failure($summary, $observations, $last, $lastFailure, $location);
            }

            $this->sleepUntil(\min($observedAt + $this->intervalSeconds, $deadline->time));
        }
    }
}
