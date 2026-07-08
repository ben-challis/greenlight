<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Test\ExpectationCounter;

/**
 * A fluent chain of matchers anchored on a single subject value.
 *
 * Create instances via Expect::that().
 *
 * A failed matcher throws ExpectationFailed immediately.
 *
 * toEqual() semantics (deep equality, everything else uses identity):
 *
 * - ints and floats compare by numeric value, so 1 equals 1.0; NAN equals
 *   nothing, including itself
 * - all other scalars and null compare strictly, so '1' does not equal 1
 * - arrays are equal when they hold the same keys, in any order, with
 *   recursively equal values
 * - enum cases compare by identity
 * - DateTimeInterface instances are equal when they denote the same instant
 *   at microsecond precision; the timezone is ignored
 * - other objects are equal when they share the exact class and every
 *   property, including private and inherited ones, is recursively equal;
 *   cyclic structures are compared without recursing forever
 * - closures and resources compare by identity
 */
final class Expectation
{
    private bool $negated = false;

    /**
     * @internal use Expect::that() instead
     *
     * @param list<ExpectationExtension> $extensions
     */
    public function __construct(
        private readonly mixed $subject,
        private readonly ValueRenderer $renderer,
        private readonly array $extensions = [],
    ) {}

    /**
     * Dispatches extension matchers: an ExpectationExtension providing a
     * matcher named like the called method is evaluated against the subject
     * with the given arguments.
     *
     * Extensions cannot shadow native matchers, which always win by existing
     * as real methods.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): self
    {
        foreach ($this->extensions as $extension) {
            $matcher = $extension->matchers()[$name] ?? null;

            if ($matcher === null) {
                continue;
            }

            return $this->verify(
                $matcher($this->subject, ...$arguments) === true,
                'to satisfy the extension matcher ' . $name,
            );
        }

        throw new \BadMethodCallException(\sprintf(
            'No matcher named %s exists natively or in any registered expectation extension.',
            $name,
        ));
    }

    /**
     * Inverts the next matcher in the chain and is consumed by it. Subject
     * type guards are not inverted: a matcher applied to a subject it cannot
     * work on fails regardless of negation.
     */
    public function not(): self
    {
        $this->negated = true;

        return $this;
    }

    /**
     * Re-anchors the chain on a new subject. Any pending not() does not carry
     * over.
     */
    public function and(mixed $value): self
    {
        return new self($value, $this->renderer, $this->extensions);
    }

    /**
     * Identity: passes when the subject is the expected value (===).
     *
     * @throws ExpectationFailed
     */
    public function toBe(mixed $expected): self
    {
        return $this->verify(
            $this->subject === $expected,
            'to be ' . $this->renderer->render($expected),
            $this->renderer->render($expected),
        );
    }

    /**
     * Deep equality; the exact semantics are documented on this class.
     *
     * @throws ExpectationFailed
     */
    public function toEqual(mixed $expected): self
    {
        return $this->verify(
            Equality::equals($this->subject, $expected),
            'to equal ' . $this->renderer->render($expected),
            $this->renderer->render($expected),
        );
    }

    /**
     * @param class-string $class
     *
     * @throws ExpectationFailed
     */
    public function toBeInstanceOf(string $class): self
    {
        return $this->verify(
            $this->subject instanceof $class,
            'to be an instance of ' . $class,
            $class,
        );
    }

    /**
     * @throws ExpectationFailed
     */
    public function toBeTrue(): self
    {
        return $this->verify($this->subject === true, 'to be true', 'true');
    }

    /**
     * @throws ExpectationFailed
     */
    public function toBeFalse(): self
    {
        return $this->verify($this->subject === false, 'to be false', 'false');
    }

    /**
     * @throws ExpectationFailed
     */
    public function toBeNull(): self
    {
        return $this->verify($this->subject === null, 'to be null', 'null');
    }

    /**
     * Substring check for string subjects (the needle must then be a string),
     * membership check by identity (===) for iterable subjects. Traversable
     * subjects are consumed by the check.
     *
     * @throws ExpectationFailed
     */
    public function toContain(mixed $needle): self
    {
        if (\is_string($this->subject)) {
            if (!\is_string($needle)) {
                return $this->usageFailure(\sprintf(
                    'toContain() on a string subject requires a string needle, got %s.',
                    \get_debug_type($needle),
                ));
            }

            return $this->verify(
                \str_contains($this->subject, $needle),
                'to contain ' . $this->renderer->render($needle),
                $this->renderer->render($needle),
            );
        }

        if (\is_iterable($this->subject)) {
            $found = false;

            foreach ($this->subject as $element) {
                if ($element === $needle) {
                    $found = true;

                    break;
                }
            }

            return $this->verify(
                $found,
                'to contain ' . $this->renderer->render($needle),
                $this->renderer->render($needle),
            );
        }

        return $this->usageFailure(\sprintf(
            'toContain() requires a string or iterable subject, got %s.',
            \get_debug_type($this->subject),
        ));
    }

    /**
     * The subject must be countable or traversable. Traversable subjects are
     * consumed by the count.
     *
     * @throws ExpectationFailed
     */
    public function toHaveCount(int $count): self
    {
        if (\is_countable($this->subject)) {
            $actualCount = \count($this->subject);
        } elseif ($this->subject instanceof \Traversable) {
            $actualCount = \iterator_count($this->subject);
        } else {
            return $this->usageFailure(\sprintf(
                'toHaveCount() requires a countable or traversable subject, got %s.',
                \get_debug_type($this->subject),
            ));
        }

        return $this->verify(
            $actualCount === $count,
            \sprintf('to have count %d', $count),
            \sprintf('count %d', $count),
            \sprintf('%s with count %d', $this->renderer->render($this->subject), $actualCount),
        );
    }

    /**
     * The subject must be an array (checked with array_key_exists) or an
     * ArrayAccess implementation (checked with offsetExists).
     *
     * @throws ExpectationFailed
     */
    public function toHaveKey(int|string $key): self
    {
        if (\is_array($this->subject)) {
            $hasKey = \array_key_exists($key, $this->subject);
        } elseif ($this->subject instanceof \ArrayAccess) {
            $hasKey = $this->subject->offsetExists($key);
        } else {
            return $this->usageFailure(\sprintf(
                'toHaveKey() requires an array or ArrayAccess subject, got %s.',
                \get_debug_type($this->subject),
            ));
        }

        return $this->verify(
            $hasKey,
            'to have key ' . $this->renderer->render($key),
            $this->renderer->render($key),
        );
    }

    /**
     * @throws ExpectationFailed
     */
    public function toBeGreaterThan(int|float $bound): self
    {
        if (!\is_int($this->subject) && !\is_float($this->subject)) {
            return $this->usageFailure(\sprintf(
                'toBeGreaterThan() requires an int or float subject, got %s.',
                \get_debug_type($this->subject),
            ));
        }

        return $this->verify(
            $this->subject > $bound,
            'to be greater than ' . $this->renderer->render($bound),
            'greater than ' . $this->renderer->render($bound),
        );
    }

    /**
     * @throws ExpectationFailed
     */
    public function toBeLessThan(int|float $bound): self
    {
        if (!\is_int($this->subject) && !\is_float($this->subject)) {
            return $this->usageFailure(\sprintf(
                'toBeLessThan() requires an int or float subject, got %s.',
                \get_debug_type($this->subject),
            ));
        }

        return $this->verify(
            $this->subject < $bound,
            'to be less than ' . $this->renderer->render($bound),
            'less than ' . $this->renderer->render($bound),
        );
    }

    /**
     * Passes when abs(subject - of) <= delta.
     *
     * @throws ExpectationFailed
     */
    public function toBeWithin(float $delta, float $of): self
    {
        if (!\is_int($this->subject) && !\is_float($this->subject)) {
            return $this->usageFailure(\sprintf(
                'toBeWithin() requires an int or float subject, got %s.',
                \get_debug_type($this->subject),
            ));
        }

        $bounds = \sprintf(
            'within %s of %s',
            $this->renderer->render($delta),
            $this->renderer->render($of),
        );

        return $this->verify(
            \abs($this->subject - $of) <= $delta,
            'to be ' . $bounds,
            $bounds,
        );
    }

    /**
     * @throws \InvalidArgumentException when the pattern is not a valid regular expression
     * @throws ExpectationFailed
     */
    public function toMatch(string $pattern): self
    {
        $this->requireValidPattern($pattern, 'toMatch');

        if (!\is_string($this->subject)) {
            return $this->usageFailure(\sprintf(
                'toMatch() requires a string subject, got %s.',
                \get_debug_type($this->subject),
            ));
        }

        return $this->verify(
            \preg_match($pattern, $this->subject) === 1,
            'to match ' . $pattern,
            $pattern,
        );
    }

    /**
     * @throws ExpectationFailed
     */
    public function toStartWith(string $prefix): self
    {
        if (!\is_string($this->subject)) {
            return $this->usageFailure(\sprintf(
                'toStartWith() requires a string subject, got %s.',
                \get_debug_type($this->subject),
            ));
        }

        return $this->verify(
            \str_starts_with($this->subject, $prefix),
            'to start with ' . $this->renderer->render($prefix),
            $this->renderer->render($prefix),
        );
    }

    /**
     * @throws ExpectationFailed
     */
    public function toEndWith(string $suffix): self
    {
        if (!\is_string($this->subject)) {
            return $this->usageFailure(\sprintf(
                'toEndWith() requires a string subject, got %s.',
                \get_debug_type($this->subject),
            ));
        }

        return $this->verify(
            \str_ends_with($this->subject, $suffix),
            'to end with ' . $this->renderer->render($suffix),
            $this->renderer->render($suffix),
        );
    }

    /**
     * The subject must be a callable; it is invoked with no arguments. Passes
     * when it throws an instance of the given class whose message matches the
     * optional regular expression.
     *
     * Under not(), any throwable that does not satisfy both conditions is
     * swallowed and counts as a pass.
     *
     * @param class-string<\Throwable> $throwable
     *
     * @throws \InvalidArgumentException when the matching pattern is not a valid regular expression
     * @throws ExpectationFailed
     */
    public function toThrow(string $throwable, ?string $matching = null): self
    {
        if ($matching !== null) {
            $this->requireValidPattern($matching, 'toThrow');
        }

        if (!\is_callable($this->subject)) {
            return $this->usageFailure(\sprintf(
                'toThrow() requires a callable subject, got %s.',
                \get_debug_type($this->subject),
            ));
        }

        $thrown = null;

        try {
            ($this->subject)();
        } catch (\Throwable $caught) {
            $thrown = $caught;
        }

        $matched = $thrown instanceof $throwable
            && ($matching === null || \preg_match($matching, $thrown->getMessage()) === 1);

        $description = 'to throw ' . $throwable;

        if ($matching !== null) {
            $description .= ' with message matching ' . $matching;
        }

        $actual = $thrown instanceof \Throwable
            ? \sprintf(
                'a callable that threw %s with message %s',
                $thrown::class,
                $this->renderer->render($thrown->getMessage()),
            )
            : 'a callable that threw nothing';

        return $this->verify($matched, $description, $throwable, $actual);
    }

    /**
     * @param non-empty-string $description sentence fragment starting with
     *   "to", negation inserts "not" in front of it
     */
    private function verify(bool $matched, string $description, ?string $expected = null, ?string $actual = null): self
    {
        ExpectationCounter::increment();
        $negated = $this->negated;
        $this->negated = false;

        if ($negated ? !$matched : $matched) {
            return $this;
        }

        $actual ??= $this->renderer->render($this->subject);

        throw ExpectationFailed::fromDetail(new FailureDetail(
            \sprintf('Expected %s %s%s.', $actual, $negated ? 'not ' : '', $description),
            $negated && $expected !== null ? 'not ' . $expected : $expected,
            $actual,
            CallSite::capture(),
        ));
    }

    /**
     * A matcher applied to a subject it cannot work on. Reported as a plain
     * failure that ignores negation, so not() cannot turn misuse into a pass.
     *
     * @param non-empty-string $message
     */
    private function usageFailure(string $message): never
    {
        $this->negated = false;

        throw ExpectationFailed::fromDetail(new FailureDetail(
            $message,
            null,
            $this->renderer->render($this->subject),
            CallSite::capture(),
        ));
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function requireValidPattern(string $pattern, string $matcher): void
    {
        if (@\preg_match($pattern, '') === false) {
            throw new \InvalidArgumentException(\sprintf(
                '%s() received an invalid regular expression: %s',
                $matcher,
                $pattern,
            ));
        }
    }
}
