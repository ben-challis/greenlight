<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

use Greenlight\Expect\Equality;
use Greenlight\Expect\ValueRenderer;

/**
 * One planned call pattern on a doubled method: which arguments it accepts,
 * how often it may run, and what it does when it runs.
 *
 * Built fluently from MockPlan::expects() and consumed by the call handler
 * and the verifier.
 *
 * Argument values compare with the same deep equality as Expect's toEqual().
 *
 * @internal
 */
final class MethodExpectation
{
    public int $actualCalls = 0;

    /**
     * @var list<mixed>|null null means any arguments
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

    private ?\Closure $callback = null;

    private ?\Throwable $throwable = null;

    /**
     * @var array<int, list<ArgumentCaptor>>
     */
    private array $registeredCaptors = [];

    /**
     * @param non-empty-string $method
     */
    public function __construct(
        public readonly string $method,
    ) {}

    public function with(mixed ...$arguments): self
    {
        $this->arguments = \array_values($arguments);

        return $this;
    }

    public function once(): self
    {
        $this->minimumCalls = 1;
        $this->maximumCalls = 1;

        return $this;
    }

    public function times(int $count): self
    {
        if ($count < 0) {
            throw DoublesError::invalidTimes($count);
        }

        $this->minimumCalls = $count;
        $this->maximumCalls = $count;

        return $this;
    }

    public function atLeast(int $count): self
    {
        if ($count < 1) {
            throw DoublesError::invalidAtLeast($count);
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

    public function andReturns(mixed $value): self
    {
        $this->assertNoAnswerConfigured();

        $this->hasReturnValue = true;
        $this->returnValue = $value;

        return $this;
    }

    /**
     * Each matched call consumes the next value; a matched call after the
     * last value is an authoring error.
     */
    public function andReturnsSequence(mixed ...$values): self
    {
        $this->assertNoAnswerConfigured();

        $sequence = \array_values($values);

        if ($sequence === []) {
            throw DoublesError::emptySequence($this->method);
        }

        $this->sequence = $sequence;

        return $this;
    }

    /**
     * The closure receives the call's arguments and its return value is
     * handed back to the caller.
     */
    public function andReturnsUsing(\Closure $answer): self
    {
        $this->assertNoAnswerConfigured();

        $this->callback = $answer;

        return $this;
    }

    public function andThrows(\Throwable $throwable): self
    {
        $this->assertNoAnswerConfigured();

        $this->throwable = $throwable;

        return $this;
    }

    /**
     * Records the argument at $position on every call this expectation
     * answers. Returns the captor rather than the expectation, so it
     * deliberately ends the fluent chain; configure counts and answers
     * before calling it.
     */
    public function captureArgument(int $position = 0): ArgumentCaptor
    {
        if ($position < 0) {
            throw DoublesError::invalidCaptorPosition($position);
        }

        $captor = new ArgumentCaptor();
        $this->registeredCaptors[$position][] = $captor;

        return $captor;
    }

    /**
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

            if (!Equality::equals($expected, $arguments[$position])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Feeds every captor its argument. Called only for the expectation that
     * won the call, never during matching probes, so losing candidates
     * cannot pollute a captor.
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

    public function isSaturated(): bool
    {
        return $this->maximumCalls !== null && $this->actualCalls >= $this->maximumCalls;
    }

    public function isSatisfied(): bool
    {
        return $this->actualCalls >= $this->minimumCalls
            && ($this->maximumCalls === null || $this->actualCalls <= $this->maximumCalls);
    }

    public function hasConfiguredReturnValue(): bool
    {
        return $this->hasReturnValue;
    }

    public function configuredReturnValue(): mixed
    {
        return $this->returnValue;
    }

    public function hasSequence(): bool
    {
        return $this->sequence !== null;
    }

    public function consumeSequenceValue(): mixed
    {
        if ($this->sequence === null || !\array_key_exists($this->sequenceIndex, $this->sequence)) {
            throw DoublesError::sequenceExhausted($this->method, \count($this->sequence ?? []));
        }

        return $this->sequence[$this->sequenceIndex++];
    }

    public function configuredCallback(): ?\Closure
    {
        return $this->callback;
    }

    public function configuredThrowable(): ?\Throwable
    {
        return $this->throwable;
    }

    public function describeCall(ValueRenderer $renderer): string
    {
        if ($this->arguments === null) {
            return $this->method . '(any arguments)';
        }

        $parts = \array_map(
            static fn(mixed $argument): string => $argument instanceof ArgumentMatcher
                ? $argument->describe()
                : $renderer->render($argument),
            $this->arguments,
        );

        return $this->method . '(' . \implode(', ', $parts) . ')';
    }

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

    public static function timesPhrase(int $count): string
    {
        return $count === 1 ? '1 time' : $count . ' times';
    }

    private function assertNoAnswerConfigured(): void
    {
        if ($this->hasReturnValue || $this->sequence !== null || $this->callback instanceof \Closure || $this->throwable instanceof \Throwable) {
            throw DoublesError::conflictingAnswers($this->method);
        }
    }
}
