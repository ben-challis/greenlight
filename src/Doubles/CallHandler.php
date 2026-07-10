<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

use Greenlight\Core\Result\FailureDetail;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\ValueRenderer;

/**
 * Runtime behind every generated proxy method.
 *
 * invoke() records the call, then answers it according to the double's kind.
 *
 * A mock answers only what was explicitly configured, a stub errors on any
 * call, and a spy records interactions and errors on calls to value-returning
 * methods.
 *
 * @internal
 */
final readonly class CallHandler
{
    public function __construct(
        private DoubleState $state,
        private ValueRenderer $renderer,
    ) {}

    /**
     * @param list<mixed> $arguments
     */
    public function invoke(object $double, string $method, array $arguments): mixed
    {
        $this->state->recordedCalls[$method][] = $arguments;

        return match ($this->state->kind) {
            DoubleKind::Mock => $this->invokeOnMock($double, $method, $arguments),
            DoubleKind::Stub => throw DoublesError::stubWasCalled($this->state->type, $method),
            DoubleKind::Spy => $this->invokeOnSpy($double, $method),
        };
    }

    /**
     * @param list<mixed> $arguments
     */
    private function invokeOnMock(object $double, string $method, array $arguments): mixed
    {
        foreach ($this->state->expectationsFor($method) as $expectation) {
            if ($expectation->isSaturated() || !$expectation->matchesArguments($arguments)) {
                continue;
            }

            ++$expectation->actualCalls;

            return $this->answer($expectation, $double, $method);
        }

        $detail = $this->unexpectedCallDetail($method, $arguments);
        $this->state->callFailures[] = $detail;

        throw ExpectationFailed::fromDetail($detail);
    }

    private function invokeOnSpy(object $double, string $method): mixed
    {
        if ($this->returnsNothing($double, $method)) {
            return null;
        }

        throw DoublesError::spyCannotAnswer($this->state->type, $method);
    }

    private function answer(MethodExpectation $expectation, object $double, string $method): mixed
    {
        $throwable = $expectation->configuredThrowable();

        if ($throwable instanceof \Throwable) {
            throw $throwable;
        }

        if ($expectation->hasConfiguredReturnValue()) {
            return $expectation->configuredReturnValue();
        }

        if ($this->returnsNothing($double, $method)) {
            return null;
        }

        throw DoublesError::returnNotConfigured($this->state->type, $method);
    }

    /**
     * Only void and undeclared return types need no configured answer.
     * Anything else, including never (which can only be satisfied by
     * andThrows()), must be stated explicitly.
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
            ? \sprintf('no call to %s() was expected', $method)
            : \implode('; ', \array_map(
                fn(MethodExpectation $expectation): string => \sprintf(
                    '%s %s',
                    $expectation->describeCall($this->renderer),
                    $expectation->describeExpectedCount(),
                ),
                $declared,
            ));

        $rendered = \array_map($this->renderer->render(...), $arguments);

        return new FailureDetail(
            \sprintf('Unexpected call to %s::%s() on a mock.', $this->state->type, $method),
            $expected,
            \sprintf('%s(%s)', $method, \implode(', ', $rendered)),
        );
    }
}
