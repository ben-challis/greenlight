<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Collects poll options until `within()` sets the deadline.
 * Use `Expect::calling(...)->returnValue()->eventually()` to create this object.
 *
 * @template T
 */
final class PendingEventually
{
    private bool $negated = false;
    /** @var non-empty-string|null */
    private ?string $reason = null;

    /** @return self<T> */
    public function not(): self
    {
        $this->negated = true;
        return $this;
    }

    /**
     * @param non-empty-string $reason
     * @return self<T>
     * @throws ExpectationFailed
     */
    public function because(string $reason): self
    {
        new MatcherEvaluation(null, $this->renderer)->because($reason);
        $this->reason = $reason;
        return $this;
    }

    private const float DEFAULT_INTERVAL_SECONDS = 0.025;

    private float $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS;

    /**
     * @var list<class-string<\Exception>>
     */
    private array $retryOnExceptions = [];

    /**
     * @internal Greenlight constructs temporal expectations.
     *
     * @param \Closure(): T $probe
     * @param list<ExpectationExtension> $extensions
     */
    private function __construct(
        private readonly \Closure $probe,
        private readonly PollingClock $clock,
        private readonly ?float $attemptDeadline,
        private readonly ValueRenderer $renderer,
        private readonly array $extensions,
    ) {}

    /**
     * @internal Use Expect::calling(...)->returnValue()->eventually() instead.
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
        ValueRenderer $renderer,
        array $extensions,
    ): self {
        return new self($probe, $clock, $attemptDeadline, $renderer, $extensions);
    }

    /**
     * @return self<T>
     *
     * @throws \InvalidArgumentException if the interval is not finite or is less than 0.001 seconds
     */
    public function pollEvery(float $seconds): self
    {
        if (!\is_finite($seconds) || $seconds < 0.001) {
            throw new \InvalidArgumentException(\sprintf(
                'Set Polling interval to a finite value of at least %.3f seconds.',
                0.001,
            ));
        }

        $this->intervalSeconds = $seconds;

        return $this;
    }

    /**
     * @param class-string<\Exception> ...$types
     *
     * @return self<T>
     *
     * @throws \InvalidArgumentException if a type does not extend Exception
     */
    public function retryOnException(string ...$types): self
    {
        $validated = \array_map($this->requireExceptionType(...), $types);

        $this->retryOnExceptions = \array_values(\array_unique([
            ...$this->retryOnExceptions,
            ...$validated,
        ]));

        return $this;
    }

    /**
     * @throws ExpectationFailed
     * @return EventuallyExpectation<T>
     *
     * @throws \InvalidArgumentException if the duration is not finite or is not positive
     */
    public function within(float $seconds): EventuallyExpectation
    {
        if (!\is_finite($seconds) || $seconds <= 0.0) {
            throw new \InvalidArgumentException(\sprintf(
                'Set Eventually duration to a finite value greater than %.3f seconds.',
                0.0,
            ));
        }

        $expectation = EventuallyExpectation::create(
            $this->probe,
            $this->clock,
            $this->attemptDeadline,
            $this->intervalSeconds,
            $seconds,
            $this->retryOnExceptions,
            $this->renderer,
            $this->extensions,
        );
        if ($this->negated) {
            $expectation->not();
        }
        if ($this->reason !== null) {
            $expectation->because($this->reason);
        }
        $this->negated = false;
        $this->reason = null;
        return $expectation;
    }

    /**
     * @return class-string<\Exception>
     */
    private function requireExceptionType(string $type): string
    {
        if (!\is_a($type, \Exception::class, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Retry exception type "%s" must extend Exception.',
                $type,
            ));
        }

        return $type;
    }
}
