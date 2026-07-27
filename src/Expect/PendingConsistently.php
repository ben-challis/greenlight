<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Collects poll options until `for()` sets the duration.
 *
 * @template T
 */
final class PendingConsistently
{
    private const float DEFAULT_INTERVAL_SECONDS = 0.025;

    private float $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS;

    /**
     * @param \Closure(): T $probe
     * @param list<ExpectationExtension> $extensions
     */
    public function __construct(
        private readonly \Closure $probe,
        private readonly PollingClock $clock,
        private readonly ?float $attemptDeadline,
        private readonly ValueRenderer $renderer,
        private readonly array $extensions,
    ) {}

    /**
     * @return self<T>
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
     * @return ConsistentlyExpectation<T>
     */
    public function for(float $seconds): ConsistentlyExpectation
    {
        if (!\is_finite($seconds) || $seconds <= 0.0) {
            throw new \InvalidArgumentException(
                'Use a finite consistency duration greater than 0.000 seconds.',
            );
        }

        return new ConsistentlyExpectation(
            $this->probe,
            $this->clock,
            $this->attemptDeadline,
            $this->intervalSeconds,
            $seconds,
            $this->renderer,
            $this->extensions,
        );
    }
}
