<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Entry point of the expectation API. Inject it into a test or construct it
 * directly, then anchor a matcher chain on a subject with that().
 *
 * The default failure mode is throw on first failure: a failed matcher throws
 * ExpectationFailed immediately. softly() runs a callable against an Expect
 * that collects failures instead, then throws a single aggregate at the end.
 */
final class Expect
{
    private FailureSink $sink;

    private readonly ValueRenderer $renderer;

    /**
     * @param list<ExpectationExtension> $extensions matchers contributed by
     *                                               plugins, dispatched by
     *                                               name from the expectation
     *                                               chain
     */
    public function __construct(
        private readonly array $extensions = [],
    ) {
        $this->sink = new ThrowingFailureSink();
        $this->renderer = new ValueRenderer();
    }

    public function that(mixed $value): Expectation
    {
        return new Expectation($value, $this->sink, $this->renderer, $this->extensions);
    }

    /**
     * Runs the callable with an Expect whose failed expectations are collected
     * instead of thrown, then throws one aggregate ExpectationFailed carrying
     * every failure. Nothing is thrown when all expectations pass. This
     * instance itself is untouched: expectations made on it keep failing fast.
     *
     * @param callable(Expect): void $expectations
     *
     * @throws ExpectationFailed
     */
    public function softly(callable $expectations): void
    {
        $sink = new CollectingFailureSink();
        $soft = new self($this->extensions);
        $soft->sink = $sink;

        $expectations($soft);

        $details = $sink->details();

        if ($details !== []) {
            throw ExpectationFailed::fromDetails($details);
        }
    }

    /**
     * @return list<ExpectationExtension>
     */
    public function extensions(): array
    {
        return $this->extensions;
    }
}
