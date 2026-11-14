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

    private readonly MatcherModifiers $modifiers;

    /**
     * @internal
     *
     * @param \Closure(): T $call
     * @param list<ExpectationExtension> $extensions
     */
    public function __construct(
        private readonly \Closure $call,
        private readonly ValueRenderer $renderer,
        private readonly array $extensions = [],
    ) {
        $this->modifiers = new MatcherModifiers();
    }

    /** @return self<T> */
    public function not(): self
    {
        $this->modifiers->negate();

        return $this;
    }

    /**
     * Sets a reason for all subsequent matchers in the chain.
     * Another `because()` call replaces the reason.
     *
     * @param non-empty-string $reason
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function because(string $reason): self
    {
        $this->modifiers->because($reason, static fn(): string => 'null');

        return $this;
    }

    /**
     * Checks identity with the return value. An unexpected throwable propagates.
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toReturn(mixed $expected): self
    {
        $this->returnValue()->toBe($expected);

        return $this;
    }

    /**
     * Selects the return value without invoking the call.
     *
     * @return ReturnValueExpectation<T>
     *
     * @throws ExpectationFailed
     */
    public function returnValue(): ReturnValueExpectation
    {
        $value = new ReturnValueExpectation($this->replay(...), $this->call, $this->renderer, $this->extensions);

        $this->modifiers->passOn($value->not(...), $value->because(...));

        return $value;
    }

    /**
     * Checks the thrown type, exact object, or typed callback constraint.
     * With no constraint, matches any Throwable.
     *
     * @template TThrowable of \Throwable
     *
     * @param class-string<TThrowable>|TThrowable|\Closure(TThrowable): void $throwable
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toThrow(
        string|\Closure|\Throwable $throwable = \Throwable::class,
        ?string $matching = null,
        ?string $message = null,
    ): self {
        $evaluation = new MatcherEvaluation($this->replay(...), $this->renderer);

        $this->modifiers->passOn($evaluation->not(...), $evaluation->because(...));

        $evaluation->toThrow($throwable, $matching, $message);

        return $this;
    }

    /**
     * @return PendingEventuallyCall<T>
     *
     * @throws ExpectationFailed
     */
    public function eventually(): PendingEventuallyCall
    {
        $pending = new PendingEventuallyCall($this->call, $this->renderer, $this->extensions);

        $this->modifiers->passOn($pending->not(...), $pending->because(...));

        return $pending;
    }

    /**
     * @return PendingConsistentlyCall<T>
     *
     * @throws ExpectationFailed
     */
    public function consistently(): PendingConsistentlyCall
    {
        $pending = new PendingConsistentlyCall($this->call, $this->renderer, $this->extensions);

        $this->modifiers->passOn($pending->not(...), $pending->because(...));

        return $pending;
    }

    /** @return T */
    private function replay(): mixed
    {
        $this->outcome ??= CallOutcome::capture($this->call);

        return $this->outcome->replay();
    }
}
