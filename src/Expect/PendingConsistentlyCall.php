<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Collects time controls for a call expectation.
 * @template T
 */
final readonly class PendingConsistentlyCall
{
    /** @var PendingConsistently<\Closure(): T> */
    private PendingConsistently $pending;

    /**
     * @internal
     * @param \Closure(): T $call
     * @param list<ExpectationExtension> $extensions
     */
    public function __construct(\Closure $call, private ValueRenderer $renderer, private array $extensions)
    {
        $this->pending = PendingConsistently::create(
            static fn() => CallOutcome::capture($call)->replay(...),
            ExpectationRuntime::clock(),
            ExpectationRuntime::deadline(),
            $renderer,
            $extensions,
        );
    }

    /** @return self<T> */
    public function not(): self
    {
        $this->pending->not();
        return $this;
    }

    /**
     * @param non-empty-string $reason
     * @return self<T>
     * @throws ExpectationFailed
     */
    public function because(string $reason): self
    {
        $this->pending->because($reason);
        return $this;
    }

    /** @return self<T> */
    public function pollEvery(float $seconds): self
    {
        $this->pending->pollEvery($seconds);
        return $this;
    }

    /**
     * @return TemporalCallExpectation<T>
     * @throws ExpectationFailed
     */
    public function for(float $seconds): TemporalCallExpectation
    {
        return new TemporalCallExpectation($this->pending->for($seconds), $this->renderer, $this->extensions);
    }
}
