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

    private ?\Throwable $throwable = null;

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
        $this->hasReturnValue = true;
        $this->returnValue = $value;

        return $this;
    }

    public function andThrows(\Throwable $throwable): self
    {
        $this->throwable = $throwable;

        return $this;
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
            if ($expected instanceof Any) {
                continue;
            }

            if (!Equality::equals($expected, $arguments[$position])) {
                return false;
            }
        }

        return true;
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
            static fn(mixed $argument): string => $argument instanceof Any ? 'any()' : $renderer->render($argument),
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
}
