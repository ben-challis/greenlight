<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Declares the native value matcher signatures.
 *
 * @internal
 *
 * @template T
 */
trait ValueMatchers
{
    /**
     * Passes when the subject and expected value are identical (===).
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBe(mixed $expected): Expectation
    {
        return $this->matchValue('toBe', [$expected]);
    }

    /**
     * Passes when the subject and expected value satisfy the rules for deep
     * equality in the `Expectation` class description.
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toEqual(mixed $expected): Expectation
    {
        return $this->matchValue('toEqual', [$expected]);
    }

    /**
     * Uses the `toEqual()` rules but ignores list-element order. Canonicalization
     * recurses through array values. It does not inspect object properties.
     * Thus, lists in object properties keep their order. Associative arrays
     * keep their keys.
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toEqualCanonicalizing(mixed $expected): Expectation
    {
        return $this->matchValue('toEqualCanonicalizing', [$expected]);
    }

    /**
     * Passes when the subject is identical (===) to one of the options.
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeOneOf(mixed ...$options): Expectation
    {
        return $this->matchValue('toBeOneOf', [...$options]);
    }

    /**
     * Passes when the haystack contains the subject by identity (===). This
     * matcher is the reverse of `toContain()`. The check consumes a `Traversable`
     * haystack.
     *
     * @param iterable<mixed> $haystack
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeIn(iterable $haystack): Expectation
    {
        return $this->matchValue('toBeIn', [$haystack]);
    }

    /**
     * @param class-string $class
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeInstanceOf(string $class): Expectation
    {
        return $this->matchValue('toBeInstanceOf', [$class]);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeTrue(): Expectation
    {
        return $this->matchValue('toBeTrue', []);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeFalse(): Expectation
    {
        return $this->matchValue('toBeFalse', []);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeNull(): Expectation
    {
        return $this->matchValue('toBeNull', []);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeArray(): Expectation
    {
        return $this->matchValue('toBeArray', []);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeString(): Expectation
    {
        return $this->matchValue('toBeString', []);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeInt(): Expectation
    {
        return $this->matchValue('toBeInt', []);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeFloat(): Expectation
    {
        return $this->matchValue('toBeFloat', []);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeBool(): Expectation
    {
        return $this->matchValue('toBeBool', []);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeCallable(): Expectation
    {
        return $this->matchValue('toBeCallable', []);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeIterable(): Expectation
    {
        return $this->matchValue('toBeIterable', []);
    }

    /**
     * For a string subject, checks for a string needle. For an iterable
     * subject, checks for the value by identity (===). Iteration stops at the
     * first match or the end of the subject.
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toContain(mixed $needle): Expectation
    {
        return $this->matchValue('toContain', [$needle]);
    }

    /**
     * Accepts an array, `Countable`, or `Traversable` subject. Uses `count()`
     * for arrays and `Countable` objects. Otherwise, consumes the iterator.
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toHaveCount(int $count): Expectation
    {
        return $this->matchValue('toHaveCount', [$count]);
    }

    /**
     * Passes when the subject is an empty string or contains no elements.
     * Accepts a string, array, `Countable`, or `Traversable` subject. Uses
     * `count()` for arrays and `Countable` objects. For other `Traversable`
     * objects, consumes the iterator.
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeEmpty(): Expectation
    {
        return $this->matchValue('toBeEmpty', []);
    }

    /**
     * For a valid UTF-8 string, measures the number of code points. For other
     * strings, measures the number of bytes. Array and `Countable` subjects use
     * `count()`.
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toHaveLength(int $length): Expectation
    {
        return $this->matchValue('toHaveLength', [$length]);
    }

    /**
     * The subject must be an array or an `ArrayAccess` implementation. The
     * matcher uses `array_key_exists()` for arrays and `offsetExists()` for
     * `ArrayAccess`.
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toHaveKey(int|string $key): Expectation
    {
        return $this->matchValue('toHaveKey', [$key]);
    }

    /**
     * Each subset key must exist in the subject with an equal value. Equality
     * uses the `toEqual()` rules. A nested array is also a subset. The
     * related nested subject array can contain extra keys. The failure
     * identifies the first different key by its dot-separated path.
     *
     * @param array<array-key, mixed> $subset
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toContainSubset(array $subset): Expectation
    {
        return $this->matchValue('toContainSubset', [$subset]);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeGreaterThan(int|float $bound): Expectation
    {
        return $this->matchValue('toBeGreaterThan', [$bound]);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeGreaterThanOrEqual(int|float $bound): Expectation
    {
        return $this->matchValue('toBeGreaterThanOrEqual', [$bound]);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeLessThan(int|float $bound): Expectation
    {
        return $this->matchValue('toBeLessThan', [$bound]);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeLessThanOrEqual(int|float $bound): Expectation
    {
        return $this->matchValue('toBeLessThanOrEqual', [$bound]);
    }

    /**
     * Passes when the absolute difference between the numeric subject and
     * `$of` is not more than `$delta`. Use a finite tolerance of zero or more.
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeWithin(float $delta, float $of): Expectation
    {
        return $this->matchValue('toBeWithin', [$delta, $of]);
    }

    /**
     * @return Expectation<T>
     *
     * @throws \InvalidArgumentException when the pattern is not a valid regular expression
     * @throws ExpectationFailed
     */
    public function toMatch(string $pattern): Expectation
    {
        return $this->matchValue('toMatch', [$pattern]);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toStartWith(string $prefix): Expectation
    {
        return $this->matchValue('toStartWith', [$prefix]);
    }

    /**
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toEndWith(string $suffix): Expectation
    {
        return $this->matchValue('toEndWith', [$suffix]);
    }

    /**
     * The subject must be a string. The matcher passes when the string
     * contains valid JSON.
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toBeJson(): Expectation
    {
        return $this->matchValue('toBeJson', []);
    }

    /**
     * The subject must be a string that contains valid JSON. The matcher
     * decodes the subject and expected JSON. It then applies deep equality to
     * the results. Object-key order has no effect. Invalid subject JSON causes
     * an expectation failure. Invalid expected JSON causes a usage error.
     *
     * @return Expectation<T>
     *
     * @throws ExpectationFailed
     */
    public function toMatchJson(string $expected): Expectation
    {
        return $this->matchValue('toMatchJson', [$expected]);
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return Expectation<T>
     */
    abstract protected function matchValue(string $name, array $arguments): Expectation;
}
