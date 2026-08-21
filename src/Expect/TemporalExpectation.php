<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\SourceLocation;
use Greenlight\Core\Test\ExpectationCounter;

/**
 * Contains the matcher dispatch and probe operations for eventual and
 * consistent expectations.
 *
 * @template T
 * @mixin Expectation<T>
 */
abstract class TemporalExpectation
{
    private bool $negated = false;

    /** @var non-empty-string|null */
    private ?string $reason = null;

    /**
     * @internal Greenlight constructs temporal expectations.
     *
     * @param \Closure(): T $probe
     * @param list<ExpectationExtension> $extensions
     */
    public function __construct(
        /** @var \Closure(): T */
        protected readonly \Closure $probe,
        protected readonly PollingClock $clock,
        protected readonly ?float $attemptDeadline,
        protected readonly float $intervalSeconds,
        protected readonly ValueRenderer $renderer,
        protected readonly array $extensions,
    ) {}

    /** Negates the next matcher for every value returned by the probe. */
    final public function not(): static
    {
        $this->negated = true;

        return $this;
    }

    /**
     * Sets a reason for the next matcher. The next matcher consumes the
     * reason.
     *
     * If the matcher fails, the failure message ends with "because" and the
     * reason. An empty reason causes a usage failure.
     *
     * @param non-empty-string $reason
     *
     * @throws ExpectationFailed
     */
    final public function because(string $reason): static
    {
        $reason = \trim($reason);

        if ($reason === '') {
            throw ExpectationFailed::fromDetail(new FailureDetail(
                'because() requires a non-empty reason.',
                location: CallSite::capture(),
            ));
        }

        $this->reason = $reason;

        return $this;
    }

    /**
     * Runs a native or configured extension matcher against each probe value.
     *
     * @param array<array-key, mixed> $arguments
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function __call(string $name, array $arguments): Expectation
    {
        $matcher = ExpectationCall::forTemporal($name, $arguments);

        return $this->apply($matcher->invoke(...));
    }

    /**
     * @param \Closure(Expectation<T>): Expectation<T> $matcher
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final protected function apply(\Closure $matcher): Expectation
    {
        $negated = $this->negated;
        $this->negated = false;
        $reason = $this->reason;
        $this->reason = null;

        return $this->waitFor($matcher, $negated, $reason, CallSite::capture());
    }

    /**
     * @param \Closure(Expectation<T>): Expectation<T> $matcher
     * @param non-empty-string|null $reason
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    abstract protected function waitFor(
        \Closure $matcher,
        bool $negated,
        ?string $reason,
        ?SourceLocation $location,
    ): Expectation;

    /**
     * @param \Closure(Expectation<T>): Expectation<T> $matcher
     * @param non-empty-string|null $reason
     * @param list<class-string<\Exception>> $retryOnExceptions
     *
     * @return TemporalValueObservation<T>|TemporalExceptionObservation
     *
     * @throws ExpectationFailed
     */
    final protected function observe(
        \Closure $matcher,
        bool $negated,
        ?string $reason,
        array $retryOnExceptions = [],
    ): TemporalObservation {
        try {
            $subject = ($this->probe)();
        } catch (\Exception $exception) {
            if (!\array_any(
                $retryOnExceptions,
                static fn(string $type): bool => $exception instanceof $type,
            )) {
                throw $exception;
            }

            return TemporalObservation::threw(
                $exception,
                \sprintf(
                    'threw %s with message %s',
                    $exception::class,
                    $this->renderer->render($exception->getMessage()),
                ),
            );
        }

        $expectation = new Expectation($subject, $this->renderer, $this->extensions);

        if ($negated) {
            $expectation->not();
        }

        if ($reason !== null) {
            $expectation->because($reason);
        }

        try {
            ExpectationCounter::withoutCounting(
                static fn(): Expectation => $matcher($expectation),
            );
        } catch (ExpectationFailed $failure) {
            return TemporalObservation::failed(
                $subject,
                $failure->detail(),
                $this->renderer->render($subject),
            );
        }

        return TemporalObservation::matched($subject, $this->renderer->render($subject));
    }

    /**
     * @param T $subject
     *
     * @return Expectation<T>
     */
    final protected function immediate(mixed $subject): Expectation
    {
        return new Expectation($subject, $this->renderer, $this->extensions);
    }

    final protected function sleepUntil(float $target): void
    {
        $stalled = 0;
        $previous = $this->clock->now();

        while (($remaining = $target - $this->clock->now()) > 0.0) {
            $this->clock->sleep($remaining);
            $current = $this->clock->now();

            if ($current <= $previous) {
                ++$stalled;

                if ($stalled >= 10_000) {
                    throw new \LogicException('The polling clock did not advance during sleep.');
                }
            } else {
                $stalled = 0;
            }

            $previous = $current;
        }
    }

    /** @throws ExpectationFailed */
    final protected function failure(
        string $summary,
        ObservationLog $observations,
        ?TemporalObservation $last,
        ?FailureDetail $lastFailure,
        ?SourceLocation $location,
    ): never {
        $message = $summary;

        if ($lastFailure instanceof FailureDetail) {
            $message .= ' Last failure: ' . $lastFailure->message;
        }

        $renderedObservations = $observations->render();

        if ($renderedObservations !== '') {
            $message .= ' Observations: ' . $renderedObservations . '.';
        }

        $expected = null;
        $actual = $last?->rendered;

        if ($lastFailure instanceof FailureDetail) {
            $expected = $lastFailure->expected;

            if (!$last?->exception instanceof \Exception) {
                $actual = $lastFailure->actual ?? $actual;
            }
        }

        throw ExpectationFailed::fromDetail(new FailureDetail(
            $message === '' ? 'Temporal expectation failed.' : $message,
            $expected,
            $actual,
            $location,
        ));
    }
}
