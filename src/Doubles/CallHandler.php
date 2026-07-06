<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

use Greenlight\Core\Result\FailureDetail;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\ValueRenderer;

/**
 * Runtime behind every generated proxy method. Records the call, then
 * answers it according to the double's kind: mocks consume a matching
 * planned call or fail immediately, stubs answer from their configuration
 * or a derived default, spies only record and answer with defaults.
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
            DoubleKind::Stub => $this->invokeOnStub($double, $method, $arguments),
            DoubleKind::Spy => DefaultValues::forMethod($double, $method),
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

    /**
     * @param list<mixed> $arguments
     */
    private function invokeOnStub(object $double, string $method, array $arguments): mixed
    {
        foreach ($this->state->expectationsFor($method) as $expectation) {
            if ($expectation->matchesArguments($arguments)) {
                return $this->answer($expectation, $double, $method);
            }
        }

        return DefaultValues::forMethod($double, $method);
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

        return DefaultValues::forMethod($double, $method);
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
