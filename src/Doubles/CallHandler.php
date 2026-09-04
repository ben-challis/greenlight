<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\ValueRenderer;
use Greenlight\Result\FailureDetail;

/**
 * Provides the runtime behavior for each method of a generated proxy class.
 *
 * invoke() records the call. It then supplies the result for the double kind.
 *
 * A mock supplies only configured results. A stub causes an error for all
 * calls. A spy records interactions. It causes an error when a method must
 * return a value.
 *
 * @internal
 */
final readonly class CallHandler
{
    /**
     * @param \WeakMap<object, DoubleState> $doubles
     */
    public function __construct(
        private DoubleState $state,
        private ValueRenderer $renderer,
        private \WeakMap $doubles,
        private MethodCallContracts $contracts,
    ) {}

    /**
     * @param non-empty-string $method
     * @param array<array-key, mixed> $arguments
     *
     * @throws ExpectationFailed
     * @throws InvalidDoubleUsage
     */
    public function invoke(object $double, string $method, array $arguments): mixed
    {
        $this->doubles[$double] = $this->state;

        $this->contracts->get($this->state->type, $method)
            ->assertCallArgumentCount(\count($arguments));

        $positionalArguments = \array_values($arguments);
        $this->state->recordedCalls[$method][] = $positionalArguments;

        return match ($this->state->kind) {
            DoubleKind::Mock => $this->invokeOnMock($double, $method, $arguments, $positionalArguments),
            DoubleKind::Stub => throw InvalidDoubleUsage::stubWasCalled($this->state->type, $method),
            DoubleKind::Spy => $this->invokeOnSpy($double, $method),
        };
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param list<mixed> $positionalArguments
     *
     * @throws ExpectationFailed
     * @throws InvalidDoubleUsage
     */
    private function invokeOnMock(object $double, string $method, array $arguments, array $positionalArguments): mixed
    {
        foreach ($this->state->expectationsFor($method) as $expectation) {
            if ($expectation->isSaturated() || !$expectation->matchesArguments($positionalArguments)) {
                continue;
            }

            ++$expectation->actualCalls;
            $expectation->recordMatchedCall($positionalArguments);

            return $this->answer($expectation, $double, $method, $arguments);
        }

        $detail = $this->unexpectedCallDetail($method, $positionalArguments);
        $this->state->callFailures[] = $detail;

        throw ExpectationFailed::fromDetail($detail);
    }

    /**
     * @throws InvalidDoubleUsage
     */
    private function invokeOnSpy(object $double, string $method): mixed
    {
        if ($this->returnsNothing($double, $method)) {
            return null;
        }

        throw InvalidDoubleUsage::spyCannotAnswer($this->state->type, $method);
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @throws InvalidDoubleUsage
     */
    private function answer(MethodExpectation $expectation, object $double, string $method, array $arguments): mixed
    {
        $throwable = $expectation->configuredThrowable();

        if ($throwable instanceof \Throwable) {
            throw $throwable;
        }

        if ($expectation->hasSequence()) {
            return $expectation->consumeSequenceValue();
        }

        $callback = $expectation->configuredCallback();

        if ($callback instanceof \Closure) {
            return $callback(...$arguments);
        }

        if ($expectation->hasConfiguredReturnValue()) {
            return $expectation->configuredReturnValue();
        }

        if ($this->returnsNothing($double, $method)) {
            return null;
        }

        throw InvalidDoubleUsage::returnNotConfigured($this->state->type, $method);
    }

    /**
     * Methods with void or undeclared return types do not need a configured
     * result. All other methods need a result. For never, use andThrows().
     */
    private function returnsNothing(object $double, string $method): bool
    {
        $type = new \ReflectionMethod($double, $method)->getReturnType();

        if ($type === null) {
            return true;
        }

        return $type instanceof \ReflectionNamedType && $type->getName() === 'void';
    }

    /**
     * @param list<mixed> $arguments
     */
    private function unexpectedCallDetail(string $method, array $arguments): FailureDetail
    {
        $declared = $this->state->expectationsFor($method);

        $expected = $declared === []
            ? \sprintf('no calls to %s()', $method)
            : \implode("\n", \array_map(
                fn(MethodExpectation $expectation): string => $expectation->describePlan($this->renderer),
                $declared,
            ));

        return new FailureDetail(
            \sprintf('The mock received an unexpected call to %s::%s().', $this->state->type, $method),
            $expected,
            MethodExpectation::renderCall($this->renderer, $method, $arguments),
        );
    }
}
