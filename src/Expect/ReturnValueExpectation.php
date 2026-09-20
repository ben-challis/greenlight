<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Checks a call's return value immediately or over time.
 * A temporal matcher invokes the original call once per observation.
 *
 * @template T
 *
 * @extends Expectation<T>
 */
final class ReturnValueExpectation extends Expectation
{
    /**
     * @internal
     *
     * @param \Closure(): T $read
     * @param \Closure(): T $probe
     * @param list<ExpectationExtension> $extensions
     */
    public function __construct(
        \Closure $read,
        private readonly \Closure $probe,
        ValueRenderer $renderer,
        array $extensions,
    ) {
        parent::__construct($read, $renderer, $extensions);
    }

    /**
     * @return PendingEventually<T>
     *
     * @throws ExpectationFailed
     */
    public function eventually(): PendingEventually
    {
        $pending = PendingEventually::create(
            $this->probe,
            ExpectationRuntime::clock(),
            ExpectationRuntime::deadline(),
            $this->renderer,
            $this->extensions,
        );

        if ($this->negated) {
            $pending->not();
        }

        if ($this->reason !== null) {
            $pending->because($this->reason);
        }

        $this->negated = false;

        return $pending;
    }

    /**
     * @return PendingConsistently<T>
     *
     * @throws ExpectationFailed
     */
    public function consistently(): PendingConsistently
    {
        $pending = PendingConsistently::create(
            $this->probe,
            ExpectationRuntime::clock(),
            ExpectationRuntime::deadline(),
            $this->renderer,
            $this->extensions,
        );

        if ($this->negated) {
            $pending->not();
        }

        if ($this->reason !== null) {
            $pending->because($this->reason);
        }

        $this->negated = false;

        return $pending;
    }
}
