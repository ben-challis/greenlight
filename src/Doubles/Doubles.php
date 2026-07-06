<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

use Greenlight\Core\Result\FailureDetail;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\ValueRenderer;
use Greenlight\Harness\Disposable;

/**
 * Factory for test doubles, and the service a test injects to create them.
 * It registers as a per-test harness service (Scope::PerTest), so every
 * double it creates is owned by the test that asked for it: when the
 * per-test scope closes, dispose() verifies every mock and then drops all
 * references, and a double can never outlive its test.
 *
 *     $gateway = $this->doubles->mock(PaymentGateway::class, function (MockPlan $plan) {
 *         $plan->expects('charge')->with($amount)->once()->andReturns($ok);
 *     });
 *
 * Mocks are strict: a call that matches no planned expectation fails the
 * test immediately. Stubs are loose: plan only what should answer, anything
 * else returns a derived default. Spies record every call; read them back
 * with callsTo() and assert with Expect. Verification failures throw a
 * single ExpectationFailed carrying one FailureDetail per unmet expectation,
 * so they render exactly like Expect failures.
 *
 * Supported subjects are interfaces and non-final classes; class doubles
 * never run the doubled constructor. Final classes, readonly classes, and
 * enums are rejected with a DoublesError suggesting an interface. There are
 * no partial mocks and no static method mocking.
 */
final class Doubles implements Disposable
{
    private readonly ProxyGenerator $generator;

    private readonly ValueRenderer $renderer;

    /**
     * States are kept for verification and never reference the proxies, so
     * doubles stay collectable the moment the test drops them.
     *
     * @var list<DoubleState>
     */
    private array $states = [];

    /**
     * @var \WeakMap<object, DoubleState>
     */
    private \WeakMap $doubles;

    /**
     * @param non-empty-string|null $proxyDirectory where generated proxy
     *                                              classes are cached;
     *                                              defaults to
     *                                              .greenlight/proxies under
     *                                              the working directory
     */
    public function __construct(?string $proxyDirectory = null)
    {
        if ($proxyDirectory === null) {
            $workingDirectory = \getcwd();

            if ($workingDirectory === false) {
                throw new DoublesError('The working directory could not be resolved; pass a proxy directory explicitly.');
            }

            $proxyDirectory = $workingDirectory . '/.greenlight/proxies';
        }

        $this->generator = new ProxyGenerator($proxyDirectory);
        $this->renderer = new ValueRenderer();
        $this->doubles = new \WeakMap();
    }

    /**
     * A strict double: every planned expectation is verified at test end and
     * any call matching no expectation fails the test immediately.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     * @param \Closure(MockPlan): void|null $plan
     *
     * @return T
     */
    public function mock(string $type, ?\Closure $plan = null): object
    {
        return $this->create($type, DoubleKind::Mock, $plan);
    }

    /**
     * A loose double: configured calls answer as planned, unconfigured calls
     * return a derived default, and nothing is enforced at test end.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     * @param \Closure(MockPlan): void|null $configure
     *
     * @return T
     */
    public function stub(string $type, ?\Closure $configure = null): object
    {
        return $this->create($type, DoubleKind::Stub, $configure);
    }

    /**
     * A recording double: every call is recorded with its arguments and
     * answered with a derived default. Read the recording back with
     * callsTo() and assert on it with Expect.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    public function spy(string $type): object
    {
        return $this->create($type, DoubleKind::Spy, null);
    }

    /**
     * The recorded calls to one method of a double created by this factory,
     * in call order, each entry the argument list of one call.
     *
     * @return list<list<mixed>>
     */
    public function callsTo(object $double, string $method): array
    {
        if (!isset($this->doubles[$double])) {
            throw new DoublesError(\sprintf('The given %s instance was not created by this Doubles factory.', $double::class));
        }

        return $this->doubles[$double]->recordedCalls[$method] ?? [];
    }

    /**
     * Runs when the per-test scope closes: verifies every mock created in
     * the test and then drops all references to the created doubles. Unmet
     * expectations throw one ExpectationFailed carrying a FailureDetail per
     * failure, which fails the test.
     */
    #[\Override]
    public function dispose(): void
    {
        $details = [];

        foreach ($this->states as $state) {
            foreach ($state->callFailures as $failure) {
                $details[] = $failure;
            }

            if ($state->kind !== DoubleKind::Mock) {
                continue;
            }

            foreach ($state->expectations as $expectation) {
                if (!$expectation->isSatisfied()) {
                    $details[] = $this->unmetExpectationDetail($state, $expectation);
                }
            }
        }

        $this->states = [];
        $this->doubles = new \WeakMap();

        if ($details !== []) {
            throw ExpectationFailed::fromDetails($details);
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     * @param \Closure(MockPlan): void|null $plan
     *
     * @return T
     */
    private function create(string $type, DoubleKind $kind, ?\Closure $plan): object
    {
        $state = new DoubleState($type, $kind);

        if ($plan instanceof \Closure) {
            $plan(new MockPlan($state));
        }

        $proxyClass = $this->generator->proxyClass($type);
        $double = new \ReflectionClass($proxyClass)->newInstanceWithoutConstructor();

        \assert($double instanceof GeneratedProxy);
        $double->__greenlightAttachHandler(new CallHandler($state, $this->renderer));

        \assert($double instanceof $type);

        $this->states[] = $state;
        $this->doubles[$double] = $state;

        return $double;
    }

    private function unmetExpectationDetail(DoubleState $state, MethodExpectation $expectation): FailureDetail
    {
        $call = $expectation->describeCall($this->renderer);

        $recorded = $state->recordedCalls[$expectation->method] ?? [];
        $actual = $recorded === []
            ? 'never called'
            : \implode('; ', \array_map(
                fn(array $arguments): string => $expectation->method . '(' . \implode(', ', \array_map(
                    $this->renderer->render(...),
                    $arguments,
                )) . ')',
                $recorded,
            ));

        return new FailureDetail(
            \sprintf(
                '%s::%s() was expected %s but was called %s.',
                $state->type,
                $expectation->method,
                $expectation->describeExpectedCount(),
                MethodExpectation::timesPhrase($expectation->actualCalls),
            ),
            \sprintf('%s %s', $call, $expectation->describeExpectedCount()),
            $actual,
        );
    }
}
