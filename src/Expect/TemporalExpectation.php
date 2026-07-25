<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\SourceLocation;
use Greenlight\Core\Test\ExpectationCounter;

/**
 * Contains the matcher dispatch and probe handling shared by eventual and
 * consistent expectations.
 *
 * @internal
 *
 * @template T
 */
abstract class TemporalExpectation
{
    private bool $negated = false;

    /**
     * @param \Closure(): T $probe
     * @param list<ExpectationExtension> $extensions
     */
    public function __construct(
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
     * Runs a configured extension matcher against each value from the probe.
     *
     * @param array<int, mixed> $arguments
     */
    final public function __call(string $name, array $arguments): Expectation
    {
        return $this->apply(
            static fn(Expectation $expectation): Expectation => $expectation->__call($name, \array_values($arguments)),
        );
    }

    final public function toBe(mixed $expected): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBe($expected));
    }

    final public function toEqual(mixed $expected): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toEqual($expected));
    }

    final public function toEqualCanonicalizing(mixed $expected): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toEqualCanonicalizing($expected));
    }

    final public function toBeOneOf(mixed ...$options): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeOneOf(...$options));
    }

    /**
     * @param iterable<mixed> $haystack
     */
    final public function toBeIn(iterable $haystack): Expectation
    {
        $stableHaystack = \is_array($haystack) ? $haystack : \iterator_to_array($haystack, false);

        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeIn($stableHaystack));
    }

    /**
     * @param class-string $class
     */
    final public function toBeInstanceOf(string $class): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeInstanceOf($class));
    }

    final public function toBeTrue(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeTrue());
    }

    final public function toBeFalse(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeFalse());
    }

    final public function toBeNull(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeNull());
    }

    final public function toBeArray(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeArray());
    }

    final public function toBeString(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeString());
    }

    final public function toBeInt(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeInt());
    }

    final public function toBeFloat(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeFloat());
    }

    final public function toBeBool(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeBool());
    }

    final public function toBeCallable(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeCallable());
    }

    final public function toBeIterable(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeIterable());
    }

    final public function toContain(mixed $needle): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toContain($needle));
    }

    final public function toHaveCount(int $count): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toHaveCount($count));
    }

    final public function toBeEmpty(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeEmpty());
    }

    final public function toHaveLength(int $length): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toHaveLength($length));
    }

    final public function toHaveKey(int|string $key): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toHaveKey($key));
    }

    /**
     * @param array<array-key, mixed> $subset
     */
    final public function toContainSubset(array $subset): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toContainSubset($subset));
    }

    final public function toBeGreaterThan(int|float $bound): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeGreaterThan($bound));
    }

    final public function toBeGreaterThanOrEqual(int|float $bound): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeGreaterThanOrEqual($bound));
    }

    final public function toBeLessThan(int|float $bound): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeLessThan($bound));
    }

    final public function toBeLessThanOrEqual(int|float $bound): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeLessThanOrEqual($bound));
    }

    final public function toBeWithin(float $delta, float $of): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeWithin($delta, $of));
    }

    final public function toMatch(string $pattern): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toMatch($pattern));
    }

    final public function toStartWith(string $prefix): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toStartWith($prefix));
    }

    final public function toEndWith(string $suffix): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toEndWith($suffix));
    }

    final public function toBeJson(): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toBeJson());
    }

    final public function toMatchJson(string $expected): Expectation
    {
        return $this->apply(static fn(Expectation $expectation): Expectation => $expectation->toMatchJson($expected));
    }

    /**
     * @param class-string<\Throwable> $throwable
     */
    final public function toThrow(string $throwable, ?string $matching = null, ?string $message = null): Expectation
    {
        if ($matching !== null && $message !== null) {
            throw ExpectationFailed::fromDetail(new FailureDetail(
                'toThrow() accepts either matching: or message:, not both.',
                location: CallSite::capture(),
            ));
        }

        return $this->apply(
            static function (Expectation $expectation) use ($throwable, $matching, $message): Expectation {
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
     * @param \Closure(Expectation): Expectation $matcher
     */
    final protected function apply(\Closure $matcher): Expectation
    {
        $negated = $this->negated;
        $this->negated = false;

        return $this->waitFor($matcher, $negated, CallSite::capture());
    }

    /**
     * @param \Closure(Expectation): Expectation $matcher
     */
    abstract protected function waitFor(
        \Closure $matcher,
        bool $negated,
        ?SourceLocation $location,
    ): Expectation;

    /**
     * @param \Closure(Expectation): Expectation $matcher
     * @param list<class-string<\Exception>> $retryOnExceptions
     */
    final protected function observe(
        \Closure $matcher,
        bool $negated,
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
                    throw new \LogicException('The polling clock did not advance while sleeping.');
                }
            } else {
                $stalled = 0;
            }

            $previous = $current;
        }
    }

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
