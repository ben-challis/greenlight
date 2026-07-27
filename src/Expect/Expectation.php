<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Test\ExpectationCounter;

/**
 * A fluent matcher chain for one subject value.
 *
 * Use `Expect::that()` to create an instance.
 *
 * A failed matcher throws `ExpectationFailed` immediately.
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
 *   values. Key order has no effect.
 *
 * - Enum cases, closures, and resources use identity.
 *
 * - `DateTimeInterface` instances are equal at the same instant and
 *   microsecond. The timezone has no effect.
 *
 * - Other objects are equal when they have the same class and recursively
 *   equal properties. This rule includes private and inherited properties.
 *   The comparison safely processes cyclic structures.
 *
 * @template T
 */
final class Expectation
{
    private bool $negated = false;

    /** @var non-empty-string|null */
    private ?string $reason = null;

    /**
     * @internal Use Expect::that() instead.
     *
     * @param T $subject
     * @param list<ExpectationExtension> $extensions
     */
    public function __construct(
        private readonly mixed $subject,
        private readonly ValueRenderer $renderer,
        private readonly array $extensions = [],
    ) {}

    /**
     * Dispatches extension matchers. If an `ExpectationExtension` provides the
     * requested matcher, this method gives it the subject and arguments.
     *
     * An extension cannot replace a native matcher. PHP calls the native
     * method directly.
     *
     * @param array<int, mixed> $arguments
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
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
            'Greenlight has no native or registered extension matcher named %s.',
            $name,
        ));
    }

    /**
     * Inverts the next matcher in the chain. That matcher consumes the
     * inversion. Negation does not apply to subject type checks. A matcher
     * fails if it cannot process the subject type.
     *
     * @return self<T>
     */
    public function not(): self
    {
        $this->negated = true;

        return $this;
    }

    /**
     * Sets a reason for the next matcher in the chain. The next matcher
     * consumes the reason.
     *
     * If the matcher fails, the failure message ends with "because" and the
     * reason. An empty reason causes a usage failure.
     *
     * @param non-empty-string $reason
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function because(string $reason): self
    {
        $reason = \trim($reason);

        if ($reason === '') {
            $this->usageFailure('because() requires a non-empty reason.');
        }

        $this->reason = $reason;

        return $this;
    }

    /**
     * Sets a new subject for the chain. The chain does not apply `not()` and
     * `because()` modifiers to the new subject.
     *
     * @template TNext
     *
     * @param TNext $value
     *
     * @return self<TNext>
     */
    public function and(mixed $value): self
    {
        return new self($value, $this->renderer, $this->extensions);
    }

    /**
     * Passes when the subject and expected value are identical (===).
     *
     * @return self<T>
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
     * Passes when the subject and expected value satisfy the rules for deep
     * equality on this class.
     *
     * @return self<T>
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
     * Uses the `toEqual()` rules but ignores list-element order at all levels.
     * Associative arrays keep their keys.
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toEqualCanonicalizing(mixed $expected): self
    {
        return $this->verify(
            Equality::equalsCanonicalizing($this->subject, $expected),
            'to equal (canonicalizing) ' . $this->renderer->render($expected),
            $this->renderer->render($expected),
        );
    }

    /**
     * Passes when the subject is identical (===) to one of the options.
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeOneOf(mixed ...$options): self
    {
        return $this->verify(
            \in_array($this->subject, $options, true),
            'to be one of ' . $this->renderer->render($options),
            'one of ' . $this->renderer->render($options),
        );
    }

    /**
     * Passes when the haystack contains the subject by identity (===). This
     * matcher is the reverse of `toContain()`. The check consumes a `Traversable`
     * haystack.
     *
     * @param iterable<mixed> $haystack
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeIn(iterable $haystack): self
    {
        $options = \is_array($haystack) ? $haystack : \iterator_to_array($haystack, false);

        return $this->verify(
            \in_array($this->subject, $options, true),
            'to be in ' . $this->renderer->render($options),
            'in ' . $this->renderer->render($options),
        );
    }

    /**
     * @param class-string $class
     *
     * @return self<T>
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
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeTrue(): self
    {
        return $this->verify($this->subject === true, 'to be true', 'true');
    }

    /**
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeFalse(): self
    {
        return $this->verify($this->subject === false, 'to be false', 'false');
    }

    /**
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeNull(): self
    {
        return $this->verify($this->subject === null, 'to be null', 'null');
    }

    /**
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeArray(): self
    {
        return $this->verify(
            \is_array($this->subject),
            'to be an array',
            'array',
            \get_debug_type($this->subject),
        );
    }

    /**
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeString(): self
    {
        return $this->verify(
            \is_string($this->subject),
            'to be a string',
            'string',
            \get_debug_type($this->subject),
        );
    }

    /**
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeInt(): self
    {
        return $this->verify(
            \is_int($this->subject),
            'to be an int',
            'int',
            \get_debug_type($this->subject),
        );
    }

    /**
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeFloat(): self
    {
        return $this->verify(
            \is_float($this->subject),
            'to be a float',
            'float',
            \get_debug_type($this->subject),
        );
    }

    /**
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeBool(): self
    {
        return $this->verify(
            \is_bool($this->subject),
            'to be a bool',
            'bool',
            \get_debug_type($this->subject),
        );
    }

    /**
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeCallable(): self
    {
        return $this->verify(
            \is_callable($this->subject),
            'to be callable',
            'callable',
            \get_debug_type($this->subject),
        );
    }

    /**
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeIterable(): self
    {
        return $this->verify(
            \is_iterable($this->subject),
            'to be iterable',
            'iterable',
            \get_debug_type($this->subject),
        );
    }

    /**
     * For a string subject, checks for a string needle. For an iterable
     * subject, checks for the value by identity (===). The check consumes a
     * `Traversable` subject.
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toContain(mixed $needle): self
    {
        if (\is_string($this->subject)) {
            if (!\is_string($needle)) {
                $this->usageFailure(\sprintf(
                    'toContain() requires a string needle for a string subject. The needle type is %s.',
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

        $this->usageFailure(\sprintf(
            'toContain() requires a string or iterable subject. The subject type is %s.',
            \get_debug_type($this->subject),
        ));
    }

    /**
     * The subject must be `Countable` or `Traversable`. The count consumes a
     * `Traversable` subject.
     *
     * @return self<T>
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
            $this->usageFailure(\sprintf(
                'toHaveCount() requires a countable or traversable subject. The subject type is %s.',
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
     * Passes when the subject is an empty string or contains no elements.
     * The subject must be a string, array, `Countable`, or iterable. The check
     * consumes a `Traversable` subject.
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeEmpty(): self
    {
        if (\is_string($this->subject)) {
            $empty = $this->subject === '';
        } elseif (\is_countable($this->subject)) {
            $empty = \count($this->subject) === 0;
        } elseif ($this->subject instanceof \Traversable) {
            $empty = \iterator_count($this->subject) === 0;
        } else {
            $this->usageFailure(\sprintf(
                'toBeEmpty() requires a string, array, Countable, or iterable subject. The subject type is %s.',
                \get_debug_type($this->subject),
            ));
        }

        return $this->verify($empty, 'to be empty', 'empty');
    }

    /**
     * For a valid UTF-8 string, measures the number of code points. For other
     * strings, measures the number of bytes. Array and `Countable` subjects use
     * `count()`.
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toHaveLength(int $length): self
    {
        if (\is_string($this->subject)) {
            $codePoints = \preg_match_all('/./us', $this->subject);
            $actualLength = $codePoints === false ? \strlen($this->subject) : $codePoints;
        } elseif (\is_countable($this->subject)) {
            $actualLength = \count($this->subject);
        } else {
            $this->usageFailure(\sprintf(
                'toHaveLength() requires a string, array, or Countable subject. The subject type is %s.',
                \get_debug_type($this->subject),
            ));
        }

        return $this->verify(
            $actualLength === $length,
            \sprintf('to have length %d', $length),
            \sprintf('length %d', $length),
            \sprintf('%s (length %d)', $this->renderer->render($this->subject), $actualLength),
        );
    }

    /**
     * The subject must be an array or an `ArrayAccess` implementation. The
     * matcher uses `array_key_exists()` for arrays and `offsetExists()` for
     * `ArrayAccess`.
     *
     * @return self<T>
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
            $this->usageFailure(\sprintf(
                'toHaveKey() requires an array or ArrayAccess subject. The subject type is %s.',
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
     * Each subset key must exist in the subject with an equal value. Equality
     * uses the `toEqual()` rules. A nested array is also a subset. The
     * related nested subject array can contain extra keys. The failure
     * identifies the first different key by its dot-separated path.
     *
     * @param array<array-key, mixed> $subset
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toContainSubset(array $subset): self
    {
        if (!\is_array($this->subject)) {
            $this->usageFailure(\sprintf(
                'toContainSubset() requires an array subject. The subject type is %s.',
                \get_debug_type($this->subject),
            ));
        }

        $difference = ArraySubset::firstDifference($subset, $this->subject);

        $description = 'to contain the subset ' . $this->renderer->render($subset);

        if ($difference !== null) {
            $description .= ' (' . $difference . ')';
        }

        return $this->verify(
            $difference === null,
            $description,
            $this->renderer->render($subset),
            $this->renderer->render($this->subject),
        );
    }

    /**
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeGreaterThan(int|float $bound): self
    {
        return $this->verify(
            $this->numericSubject('toBeGreaterThan') > $bound,
            'to be greater than ' . $this->renderer->render($bound),
            'greater than ' . $this->renderer->render($bound),
        );
    }

    /**
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeGreaterThanOrEqual(int|float $bound): self
    {
        return $this->verify(
            $this->numericSubject('toBeGreaterThanOrEqual') >= $bound,
            'to be greater than or equal to ' . $this->renderer->render($bound),
            'greater than or equal to ' . $this->renderer->render($bound),
        );
    }

    /**
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeLessThan(int|float $bound): self
    {
        return $this->verify(
            $this->numericSubject('toBeLessThan') < $bound,
            'to be less than ' . $this->renderer->render($bound),
            'less than ' . $this->renderer->render($bound),
        );
    }

    /**
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeLessThanOrEqual(int|float $bound): self
    {
        return $this->verify(
            $this->numericSubject('toBeLessThanOrEqual') <= $bound,
            'to be less than or equal to ' . $this->renderer->render($bound),
            'less than or equal to ' . $this->renderer->render($bound),
        );
    }

    /**
     * Passes when abs(subject - of) is not more than delta.
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeWithin(float $delta, float $of): self
    {
        $subject = $this->numericSubject('toBeWithin');

        $bounds = \sprintf(
            'within %s of %s',
            $this->renderer->render($delta),
            $this->renderer->render($of),
        );

        return $this->verify(
            \abs($subject - $of) <= $delta,
            'to be ' . $bounds,
            $bounds,
        );
    }

    /**
     * @return self<T>
     *
     * @throws \InvalidArgumentException when the pattern is not a valid regular expression
     * @throws ExpectationFailed
     */
    public function toMatch(string $pattern): self
    {
        $this->requireValidPattern($pattern, 'toMatch');

        return $this->verify(
            \preg_match($pattern, $this->stringSubject('toMatch')) === 1,
            'to match ' . $pattern,
            $pattern,
        );
    }

    /**
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toStartWith(string $prefix): self
    {
        return $this->verify(
            \str_starts_with($this->stringSubject('toStartWith'), $prefix),
            'to start with ' . $this->renderer->render($prefix),
            $this->renderer->render($prefix),
        );
    }

    /**
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toEndWith(string $suffix): self
    {
        return $this->verify(
            \str_ends_with($this->stringSubject('toEndWith'), $suffix),
            'to end with ' . $this->renderer->render($suffix),
            $this->renderer->render($suffix),
        );
    }

    /**
     * The subject must be a string. The matcher passes when the string
     * contains valid JSON.
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeJson(): self
    {
        return $this->verify(
            \json_validate($this->stringSubject('toBeJson')),
            'to be valid JSON',
            'valid JSON',
        );
    }

    /**
     * The subject must be a string that contains valid JSON. The matcher
     * decodes the subject and expected JSON. It then applies deep equality to
     * the results. Object-key order has no effect. Invalid subject JSON causes
     * an expectation failure. Invalid expected JSON causes a usage error.
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    public function toMatchJson(string $expected): self
    {
        $subject = $this->stringSubject('toMatchJson');

        try {
            $decodedExpected = \json_decode($expected, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->usageFailure('Pass valid JSON as the expected value to toMatchJson().');
        }

        $renderedExpected = $this->renderer->render($decodedExpected);

        try {
            $decodedSubject = \json_decode($subject, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->verify(
                false,
                'to be valid JSON matching ' . $renderedExpected,
                $renderedExpected,
            );
        }

        return $this->verify(
            Equality::equals($decodedSubject, $decodedExpected),
            'to match the JSON structure ' . $renderedExpected,
            $renderedExpected,
            $this->renderer->render($decodedSubject),
        );
    }

    /**
     * The subject must be callable. The matcher calls it with no arguments.
     * It passes when the subject throws an instance of the specified class.
     * The message must satisfy the optional regular expression or exact-text
     * constraint.
     *
     * With `not()`, a throwable that does not satisfy both conditions makes the
     * matcher pass.
     *
     * @param class-string<\Throwable> $throwable
     *
     * @return self<T>
     *
     * @throws \InvalidArgumentException when the match pattern is not a valid regular expression
     * @throws ExpectationFailed
     */
    public function toThrow(string $throwable, ?string $matching = null, ?string $message = null): self
    {
        if ($matching !== null && $message !== null) {
            $this->usageFailure('Specify matching: or message: for toThrow(). Do not specify both.');
        }

        if ($matching !== null) {
            $this->requireValidPattern($matching, 'toThrow');
        }

        if (!\is_callable($this->subject)) {
            $this->usageFailure(\sprintf(
                'toThrow() requires a callable subject. The subject type is %s.',
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
            && ($matching === null || \preg_match($matching, $thrown->getMessage()) === 1)
            && ($message === null || $thrown->getMessage() === $message);

        $description = 'to throw ' . $throwable;

        if ($matching !== null) {
            $description .= ' with message matching ' . $matching;
        } elseif ($message !== null) {
            $description .= ' with message ' . $this->renderer->render($message);
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
     * @param non-empty-string $description Sentence fragment that starts with
     *   "to". Negation puts "not" before it. A pending `because()` reason
     *   follows it.
     *
     * @return self<T>
     *
     * @throws ExpectationFailed
     */
    private function verify(bool $matched, string $description, ?string $expected = null, ?string $actual = null): self
    {
        ExpectationCounter::increment();
        $negated = $this->negated;
        $this->negated = false;
        $reason = $this->reason;
        $this->reason = null;

        if ($negated ? !$matched : $matched) {
            return $this;
        }

        $actual ??= $this->renderer->render($this->subject);

        throw ExpectationFailed::fromDetail(new FailureDetail(
            \sprintf(
                'Expected %s %s%s%s.',
                $actual,
                $negated ? 'not ' : '',
                $description,
                $reason === null ? '' : ' because ' . $reason,
            ),
            $negated && $expected !== null ? 'not ' . $expected : $expected,
            $actual,
            CallSite::capture(),
        ));
    }

    /**
     * Returns the string subject or causes a usage failure that identifies the
     * matcher.
     *
     * @throws ExpectationFailed
     */
    private function stringSubject(string $matcher): string
    {
        if (!\is_string($this->subject)) {
            $this->usageFailure(\sprintf(
                '%s() requires a string subject. The subject type is %s.',
                $matcher,
                \get_debug_type($this->subject),
            ));
        }

        return $this->subject;
    }

    /**
     * Returns the int|float subject or causes a usage failure that identifies
     * the matcher.
     *
     * @throws ExpectationFailed
     */
    private function numericSubject(string $matcher): int|float
    {
        if (!\is_int($this->subject) && !\is_float($this->subject)) {
            $this->usageFailure(\sprintf(
                '%s() requires an int or float subject. The subject type is %s.',
                $matcher,
                \get_debug_type($this->subject),
            ));
        }

        return $this->subject;
    }

    /**
     * Reports a matcher that cannot process the subject type. The failure
     * ignores negation. Thus, `not()` cannot make incorrect use pass.
     *
     * @param non-empty-string $message
     *
     * @throws ExpectationFailed
     */
    private function usageFailure(string $message): never
    {
        $this->negated = false;
        $this->reason = null;

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
        if (ErrorTrap::run(static fn(): int|false => \preg_match($pattern, ''), $warning) === false) {
            throw new \InvalidArgumentException(\sprintf(
                'The pattern for %s() is an invalid regular expression: %s%s',
                $matcher,
                $pattern,
                $warning === null ? '' : ' (' . $warning . ')',
            ));
        }
    }
}
