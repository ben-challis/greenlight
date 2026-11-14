<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Checks one value. Callable values are never invoked.
 * Use `Expect::value()` to create an expectation.
 *
 * `toEqual()` uses these rules for deep equality:
 *
 * - Integers and floats use numeric value. Thus, `1` equals `1.0`. `NAN` does not
 *   equal a value, even itself.
 *
 * - Other scalar values and `null` use strict equality. Thus, `'1'` does not
 *   equal `1`.
 *
 * - Arrays are equal when they contain the same keys and recursively equal
 *   values. Key order has no effect. Cyclic arrays are unsupported. When the
 *   comparison follows a cycle, it raises `InvalidArgumentException`.
 *
 * - Enum cases, closures, and resources use identity.
 *
 * - `DateTimeInterface` instances are equal at the same instant and
 *   microsecond. The timezone has no effect.
 *
 * - Other objects are equal when they have the same class and recursively
 *   equal properties. This rule includes private and inherited properties.
 *   The comparison safely processes cyclic object structures.
 *
 * @template T
 */
class Expectation
{
    /** @use ValueMatchers<T> */
    use ValueMatchers;

    /** @var MatcherEvaluation<T>|null */
    private ?MatcherEvaluation $evaluation = null;

    protected readonly MatcherModifiers $modifiers;

    /**
     * @internal
     *
     * @param \Closure(): T $read
     * @param list<ExpectationExtension> $extensions
     */
    public function __construct(
        private readonly \Closure $read,
        protected readonly ValueRenderer $renderer,
        protected readonly array $extensions = [],
    ) {
        $this->modifiers = new MatcherModifiers();
    }

    public function not(): static
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
     * @throws ExpectationFailed
     */
    public function because(string $reason): static
    {
        // Validate the reason before a lazy value is read.
        $this->modifiers->because($reason, static fn(): string => 'null');

        return $this;
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return Expectation<T>
     *
     * @throws \BadMethodCallException
     * @throws ExpectationFailed
     */
    public function __call(string $name, array $arguments): Expectation
    {
        foreach ($this->extensions as $extension) {
            if (isset($extension->matchers()[$name])) {
                return $this->matchValue($name, $arguments);
            }
        }

        throw new \BadMethodCallException(\sprintf('Greenlight has no native or registered extension matcher named %s.', $name));
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    protected function matchValue(string $name, array $arguments): Expectation
    {
        $this->evaluation ??= new MatcherEvaluation(($this->read)(), $this->renderer, $this->extensions);

        $this->modifiers->passOn($this->evaluation->not(...), $this->evaluation->because(...));

        ExpectationCall::forImmediate($name, $arguments)->invoke($this->evaluation);

        return $this;
    }
}
