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
 * @internal
 *
 * @template T
 */
abstract class TemporalExpectation
{
    private bool $negated = false;

    /** @var non-empty-string|null */
    private ?string $reason = null;

    /**
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

    /**
     * Negates the next matcher for every value returned by the probe.
     */
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
     * Runs a configured extension matcher against each value from the probe.
     *
     * @param array<int, mixed> $arguments
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function __call(string $name, array $arguments): Expectation
    {
        return $this->apply(
            static fn(Expectation $expectation): Expectation => $expectation->__call($name, \array_values($arguments)),
        );
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBe(mixed $expected): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBe($expected));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toEqual(mixed $expected): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toEqual($expected));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toEqualCanonicalizing(mixed $expected): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toEqualCanonicalizing($expected));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeOneOf(mixed ...$options): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeOneOf(...$options));
    }

    /**
     * @param iterable<mixed> $haystack
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeIn(iterable $haystack): Expectation
    {
        $stableHaystack = \is_array($haystack) ? $haystack : \iterator_to_array($haystack, false);

        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeIn($stableHaystack));
    }

    /**
     * @param class-string $class
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeInstanceOf(string $class): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeInstanceOf($class));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeTrue(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeTrue());
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeFalse(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeFalse());
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeNull(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeNull());
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeArray(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeArray());
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeString(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeString());
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeInt(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeInt());
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeFloat(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeFloat());
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeBool(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeBool());
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeCallable(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeCallable());
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeIterable(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeIterable());
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toContain(mixed $needle): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toContain($needle));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toHaveCount(int $count): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toHaveCount($count));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeEmpty(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeEmpty());
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toHaveLength(int $length): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toHaveLength($length));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toHaveKey(int|string $key): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toHaveKey($key));
    }

    /**
     * @param array<array-key, mixed> $subset
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toContainSubset(array $subset): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toContainSubset($subset));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeGreaterThan(int|float $bound): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeGreaterThan($bound));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeGreaterThanOrEqual(int|float $bound): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeGreaterThanOrEqual($bound));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeLessThan(int|float $bound): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeLessThan($bound));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeLessThanOrEqual(int|float $bound): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeLessThanOrEqual($bound));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeWithin(float $delta, float $of): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeWithin($delta, $of));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toMatch(string $pattern): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toMatch($pattern));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toStartWith(string $prefix): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toStartWith($prefix));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toEndWith(string $suffix): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toEndWith($suffix));
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toBeJson(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeJson());
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toMatchJson(string $expected): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toMatchJson($expected));
    }

    /**
     * @template TThrowable of \Throwable
     *
     * @param class-string<TThrowable>|\Closure(TThrowable): void $throwable
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    final public function toThrow(
        string|\Closure $throwable,
        ?string $matching = null,
        ?string $message = null,
    ): Expectation {
        if ($throwable instanceof \Closure && ($matching !== null || $message !== null)) {
            throw ExpectationFailed::fromDetail(new FailureDetail(
                'Do not specify matching: or message: when the throwable is a callback.',
                location: CallSite::capture(),
            ));
        }

        if ($matching !== null && $message !== null) {
            throw ExpectationFailed::fromDetail(new FailureDetail(
                'Specify matching: or message: for toThrow(). Do not specify both.',
                location: CallSite::capture(),
            ));
        }

        return $this->apply(
            static function (Expectation $expectation) use ($throwable, $matching, $message): Expectation {
                if ($throwable instanceof \Closure) {
                    return $expectation->toThrow($throwable);
                }

                if ($matching !== null) {
                    return $expectation->toThrow($throwable, matching: $matching);
                }

                if ($message !== null) {
                    return $expectation->toThrow($throwable, message: $message);
                }

                return $expectation->toThrow($throwable);
            },
        );
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
