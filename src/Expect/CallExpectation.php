<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Checks a call's captured outcome. Construct with `Expect::calling()`.
 * Matchers in one immediate chain share one invocation.
 *
 * @template T
 */
final class CallExpectation
{
    /** @var CallOutcome<T>|null */
    private ?CallOutcome $outcome = null;
    protected bool $negated = false;
    /** @var non-empty-string|null */
    protected ?string $reason = null;

    /**
     * @internal
     * @param \Closure(): T $call
     * @param list<ExpectationExtension> $extensions
     */
    public function __construct(
        private readonly \Closure $call,
        private readonly ValueRenderer $renderer,
        private readonly array $extensions = [],
    ) {}

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

    /**
     * Checks identity with the return value. An unexpected throwable propagates.
     * @return self<T>
     * @throws ExpectationFailed
     */
    public function toReturn(mixed $expected): self
    {
        $this->returnValue()->toBe($expected);
        return $this;
    }

    /**
     * Selects the return value without invoking the call.
     * @return ReturnValueExpectation<T>
     * @throws ExpectationFailed
     */
    public function returnValue(): ReturnValueExpectation
    {
        $value = new ReturnValueExpectation($this->replay(...), $this->call, $this->renderer, $this->extensions);
        if ($this->negated) {
            $value->not();
        }
        if ($this->reason !== null) {
            $value->because($this->reason);
        }
        $this->negated = false;
        $this->reason = null;
        return $value;
    }

    /**
     * Checks the thrown type, exact object, or typed callback constraint.
     * With no constraint, matches any Throwable.
     * @template TThrowable of \Throwable
     * @param class-string<TThrowable>|TThrowable|\Closure(TThrowable): void $throwable
     * @return self<T>
     * @throws ExpectationFailed
     */
    public function toThrow(
        string|\Closure|\Throwable $throwable = \Throwable::class,
        ?string $matching = null,
        ?string $message = null,
    ): self {
        $evaluation = new MatcherEvaluation($this->replay(...), $this->renderer);
        if ($this->negated) {
            $evaluation->not();
        }
        if ($this->reason !== null) {
            $evaluation->because($this->reason);
        }
        $this->negated = false;
        $this->reason = null;
        $evaluation->toThrow($throwable, $matching, $message);
        return $this;
    }

    /**
     * @return PendingEventuallyCall<T>
     * @throws ExpectationFailed
     */
    public function eventually(): PendingEventuallyCall
    {
        $pending = new PendingEventuallyCall($this->call, $this->renderer, $this->extensions);
        if ($this->negated) {
            $pending->not();
        }
        if ($this->reason !== null) {
            $pending->because($this->reason);
        }
        $this->negated = false;
        $this->reason = null;
        return $pending;
    }

    /**
     * @return PendingConsistentlyCall<T>
     * @throws ExpectationFailed
     */
    public function consistently(): PendingConsistentlyCall
    {
        $pending = new PendingConsistentlyCall($this->call, $this->renderer, $this->extensions);
        if ($this->negated) {
            $pending->not();
        }
        if ($this->reason !== null) {
            $pending->because($this->reason);
        }
        $this->negated = false;
        $this->reason = null;
        return $pending;
    }

    /** @return T */
    private function replay(): mixed
    {
        $this->outcome ??= CallOutcome::capture($this->call);
        return $this->outcome->replay();
    }
}
