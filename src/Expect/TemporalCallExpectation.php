<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Checks a call outcome on each poll and retains the last matched outcome.
 *
 * @template T
 */
final readonly class TemporalCallExpectation
{
    /**
     * @internal
     *
     * @param TemporalExpectation<\Closure(): T> $temporal
     * @param list<ExpectationExtension> $extensions
     */
    public function __construct(
        private TemporalExpectation $temporal,
        private ValueRenderer $renderer,
        private array $extensions,
    ) {}

    /** @return self<T> */
    public function not(): self
    {
        $this->temporal->not();

        return $this;
    }

    /**
     * @param non-empty-string $reason
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function because(string $reason): self
    {
        $this->temporal->because($reason);

        return $this;
    }

    /**
     * @return CallExpectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toReturn(mixed $expected): CallExpectation
    {
        $result = $this->temporal->evaluate('toReturn', [$expected]);

        return $this->continueWith($result->subject);
    }

    /**
     * @template TThrowable of \Throwable
     *
     * @param class-string<TThrowable>|TThrowable|\Closure(TThrowable): void $throwable
     *
     * @return CallExpectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toThrow(
        string|\Closure|\Throwable $throwable = \Throwable::class,
        ?string $matching = null,
        ?string $message = null,
    ): CallExpectation {
        $result = $this->temporal->evaluate('toThrow', [$throwable, $matching, $message]);

        return $this->continueWith($result->subject);
    }

    /**
     * @param \Closure(): T $call
     *
     * @return CallExpectation<T>
     *
     * @throws ExpectationFailed
     */
    private function continueWith(\Closure $call): CallExpectation
    {
        $expectation = new CallExpectation($call, $this->renderer, $this->extensions);
        $reason = $this->temporal->reason();

        if ($reason !== null) {
            $expectation->because($reason);
        }

        return $expectation;
    }
}
