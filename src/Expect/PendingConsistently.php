<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Collects poll options until `for()` sets the duration.
 * Use `Expect::calling(...)->returnValue()->consistently()` to create this object.
 *
 * @template T
 */
final class PendingConsistently
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
     * @internal Use Expect::calling(...)->returnValue()->consistently() instead.
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
            throw new \InvalidArgumentException(
                'Use a finite polling interval of at least 0.001 seconds.',
            );
        }

        $this->intervalSeconds = $seconds;

        return $this;
    }

    /**
     * @throws ExpectationFailed
     * @return ConsistentlyExpectation<T>
     *
     * @throws \InvalidArgumentException if the duration is not finite or is not positive
     */
    public function for(float $seconds): ConsistentlyExpectation
    {
        if (!\is_finite($seconds) || $seconds <= 0.0) {
            throw new \InvalidArgumentException(
                'Use a finite consistency duration greater than 0.000 seconds.',
            );
        }

        $expectation = ConsistentlyExpectation::create(
            $this->probe,
            $this->clock,
            $this->attemptDeadline,
            $this->intervalSeconds,
            $seconds,
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
}
