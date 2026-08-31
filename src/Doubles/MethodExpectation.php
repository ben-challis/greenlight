<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

use Greenlight\Expect\ValueRenderer;

/**
 * Defines one planned call pattern for a method of a double. The plan
 * specifies the accepted arguments, cardinality, and result.
 *
 * `MockPlan::expects()` creates this object. Its fluent plan methods are the
 * public interface. The call handler and verifier use members that have the
 * `@internal` tag.
 *
 * Bare argument values use strict comparison (`===`). Use
 * `Argument::equals()` to apply deep equality.
 *
 * @template TTarget of object = object
 * @template TMethod of non-empty-string = non-empty-string
 */
final class MethodExpectation
{
    /**
     * @internal The call handler writes this value. The verifier reads it.
     */
    public int $actualCalls = 0;

    /**
     * @var list<mixed>|null A null value accepts all argument lists.
     */
    private ?array $arguments = null;

    private int $minimumCalls = 1;

    private ?int $maximumCalls = null;

    private bool $hasReturnValue = false;

    private mixed $returnValue = null;

    /**
     * @var non-empty-list<mixed>|null
     */
    private ?array $sequence = null;

    private int $sequenceIndex = 0;

    private ?\Closure $callback = null; // @phpstan-ignore missingType.callable (The doubled method determines the callback signature.)

    private ?\Throwable $throwable = null;

    /**
     * @var array<int, list<ArgumentCaptor>>
     */
    private array $registeredCaptors = [];

    public readonly string $method;

    /**
     * @internal Only MockPlan::expects() constructs this object.
     *
     * @param MethodCallContract<TTarget, TMethod> $contract
     */
    public function __construct(private readonly MethodCallContract $contract)
    {
        $this->method = $contract->method;
    }

    /**
     * @throws InvalidDoubleUsage
     */
    public function withNoArguments(): self
    {
        return $this->withArguments([], 'withNoArguments');
    }

    /**
     * @throws InvalidDoubleUsage
     */
    public function with(mixed $first, mixed ...$rest): self
    {
        return $this->withArguments([$first, ...\array_values($rest)], 'with');
    }

    /**
     * @param list<mixed> $arguments
     * @throws InvalidDoubleUsage
     */
    private function withArguments(array $arguments, string $selector): self
    {
        $this->contract->assertPlannedArguments($selector, $arguments);
        $this->arguments = $arguments;

        return $this;
    }

    public function once(): self
    {
        $this->minimumCalls = 1;
        $this->maximumCalls = 1;

        return $this;
    }

    /**
     * @throws InvalidDoubleUsage
     */
    public function times(int $count): self
    {
        if ($count < 0) {
            throw InvalidDoubleUsage::invalidTimes($count);
        }

        $this->minimumCalls = $count;
        $this->maximumCalls = $count;

        return $this;
    }

    /**
     * @throws InvalidDoubleUsage
     */
    public function atLeast(int $count): self
    {
        if ($count < 1) {
            throw InvalidDoubleUsage::invalidAtLeast($count);
        }

        $this->minimumCalls = $count;
        $this->maximumCalls = null;

        return $this;
    }

    public function never(): self
    {
        $this->minimumCalls = 0;
        $this->maximumCalls = 0;

        return $this;
    }

    /**
     * @throws InvalidDoubleUsage
     */
    public function andReturns(mixed $value): self
    {
        $this->assertNoAnswerConfigured();

        $this->hasReturnValue = true;
        $this->returnValue = $value;

        return $this;
    }

    /**
     * Each accepted call consumes the next value. A call after the last value
     * causes an error in the test code.
     * @throws InvalidDoubleUsage
     */
    public function andReturnsSequence(mixed ...$values): self
    {
        $this->assertNoAnswerConfigured();

        $sequence = \array_values($values);

        if ($sequence === []) {
            throw InvalidDoubleUsage::emptySequence($this->method);
        }

        $this->sequence = $sequence;

        return $this;
    }

    /**
     * The closure receives the call arguments. The call returns the value
     * from the closure.
     * @throws InvalidDoubleUsage
     */
    public function andReturnsUsing(\Closure $answer): self // @phpstan-ignore missingType.callable (The doubled method determines the answer signature.)
    {
        $this->assertNoAnswerConfigured();

        $this->callback = $answer;

        return $this;
    }

    /**
     * @throws InvalidDoubleUsage
     */
    public function andThrows(\Throwable $throwable): self
    {
        $this->assertNoAnswerConfigured();

        $this->throwable = $throwable;

        return $this;
    }

    /**
     * Records the argument at `$position` each time Greenlight selects this
     * expectation for a call. The method returns the captor and ends the
     * fluent chain. Before you call this method, configure the cardinality. If
     * the doubled method returns a value, configure its result first.
     *
     * @return ArgumentCaptor<mixed>
     * @throws InvalidDoubleUsage
     */
    public function captureArgument(int $position = 0): ArgumentCaptor
    {
        if ($position < 0) {
            throw InvalidDoubleUsage::invalidCaptorPosition($position);
        }

        $captor = new ArgumentCaptor();
        $this->registeredCaptors[$position][] = $captor;

        return $captor;
    }

    /**
     * @internal Only the call handler calls this method.
     *
     * @param list<mixed> $arguments
     */
    public function matchesArguments(array $arguments): bool
    {
        if ($this->arguments === null) {
            return true;
        }

        if (\count($this->arguments) !== \count($arguments)) {
            return false;
        }

        foreach ($this->arguments as $position => $expected) {
            if ($expected instanceof ArgumentMatcher) {
                if (!$expected->matches($arguments[$position])) {
                    return false;
                }

                continue;
            }

            if ($expected !== $arguments[$position]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Adds an argument to each captor. Only the expectation selected for the
     * call uses this method. Candidate checks cannot add values to a captor.
     *
     * @internal Only the call handler calls this method.
     *
     * @param list<mixed> $arguments
     */
    public function recordMatchedCall(array $arguments): void
    {
        foreach ($this->arguments ?? [] as $position => $expected) {
            if ($expected instanceof ArgumentCaptor) {
                $expected->capture($arguments[$position]);
            }
        }

        foreach ($this->registeredCaptors as $position => $captors) {
            if (!\array_key_exists($position, $arguments)) {
                continue;
            }

            foreach ($captors as $captor) {
                $captor->capture($arguments[$position]);
            }
        }
    }

    /**
     * @internal Only the call handler calls this method.
     */
    public function isSaturated(): bool
    {
        return $this->maximumCalls !== null && $this->actualCalls >= $this->maximumCalls;
    }

    /**
     * @internal Only the verifier calls this method.
     */
    public function isSatisfied(): bool
    {
        return $this->actualCalls >= $this->minimumCalls
            && ($this->maximumCalls === null || $this->actualCalls <= $this->maximumCalls);
    }

    /**
     * @internal Only the call handler calls this method.
     */
    public function hasConfiguredReturnValue(): bool
    {
        return $this->hasReturnValue;
    }

    /**
     * @internal Only the call handler calls this method.
     */
    public function configuredReturnValue(): mixed
    {
        return $this->returnValue;
    }

    /**
     * @internal Only the call handler calls this method.
     */
    public function hasSequence(): bool
    {
        return $this->sequence !== null;
    }

    /**
     * @internal Only the call handler calls this method.
     * @throws InvalidDoubleUsage
     */
    public function consumeSequenceValue(): mixed
    {
        if ($this->sequence === null || !\array_key_exists($this->sequenceIndex, $this->sequence)) {
            throw InvalidDoubleUsage::sequenceExhausted($this->method, \count($this->sequence ?? []));
        }

        return $this->sequence[$this->sequenceIndex++];
    }

    /**
     * @internal Only the call handler calls this method.
     */
    public function configuredCallback(): ?\Closure // @phpstan-ignore missingType.callable (The doubled method determines the callback signature.)
    {
        return $this->callback;
    }

    /**
     * @internal Only the call handler calls this method.
     */
    public function configuredThrowable(): ?\Throwable
    {
        return $this->throwable;
    }

    /**
     * @internal Only failure messages use this method.
     */
    public function describeCall(ValueRenderer $renderer): string
    {
        if ($this->arguments === null) {
            return $this->method . '(all arguments)';
        }

        $parts = \array_map(
            static fn(mixed $argument): string => $argument instanceof ArgumentMatcher
                ? $argument->describe()
                : $renderer->render($argument),
            $this->arguments,
        );

        return $this->method . '(' . \implode(', ', $parts) . ')';
    }

    /**
     * Describes the planned call pattern and its cardinality. Each failure
     * message uses this format to identify an expectation.
     *
     * @internal Only failure messages use this method.
     */
    public function describePlan(ValueRenderer $renderer): string
    {
        return $this->describeCall($renderer) . ' ' . $this->describeExpectedCount();
    }

    /**
     * Describes a recorded call in the same format as describeCall().
     *
     * @internal Only failure messages use this method.
     *
     * @param list<mixed> $arguments
     */
    public static function renderCall(ValueRenderer $renderer, string $method, array $arguments): string
    {
        return $method . '(' . \implode(', ', \array_map($renderer->render(...), $arguments)) . ')';
    }

    /**
     * @internal Only failure messages use this method.
     */
    public function describeExpectedCount(): string
    {
        if ($this->maximumCalls === null) {
            return \sprintf('at least %s', self::timesPhrase($this->minimumCalls));
        }

        if ($this->maximumCalls === 0) {
            return 'never';
        }

        return \sprintf('exactly %s', self::timesPhrase($this->maximumCalls));
    }

    /**
     * @internal Only failure messages use this method.
     */
    public static function timesPhrase(int $count): string
    {
        return $count === 1 ? '1 time' : $count . ' times';
    }

    /**
     * @throws InvalidDoubleUsage
     */
    private function assertNoAnswerConfigured(): void
    {
        if ($this->hasReturnValue || $this->sequence !== null || $this->callback instanceof \Closure || $this->throwable instanceof \Throwable) {
            throw InvalidDoubleUsage::conflictingAnswers($this->method);
        }
    }
}
