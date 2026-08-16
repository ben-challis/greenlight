<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Test\ExpectationCounter;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\ValueRenderer;
use Greenlight\Harness\Disposable;

/**
 * Mocks are strict. A call without a planned expectation fails the test
 * immediately. Each return value needs a configured result. Stubs cause an
 * error for all interactions. Spies record calls to methods without a return
 * value.
 *
 * A verification failure throws one `ExpectationFailed`. It contains one
 * `FailureDetail` for each unmet expectation. Thus, the reporter shows it in
 * the same format as an `Expect` failure.
 *
 * `Doubles` supports interfaces and non-final classes. Class constructors do
 * not run. `Doubles` does not support partial mocks or static interception.
 */
final class Doubles implements Disposable
{
    private readonly ProxyGenerator $generator;

    private readonly ValueRenderer $renderer;

    /**
     * The factory stores states for verification. The states do not refer to
     * the proxy objects. Thus, PHP can collect a double after the test releases it.
     *
     * @var list<DoubleState>
     */
    private array $states = [];

    /**
     * @var \WeakMap<object, DoubleState>
     */
    private \WeakMap $doubles;

    /**
     * @param string|null $proxyDirectory Directory for generated proxy
     *   classes. An empty string is invalid. The default is a project
     *   directory in the system temporary directory. A hash of the current
     *   working directory identifies it.
     */
    public function __construct(?string $proxyDirectory = null)
    {
        if ($proxyDirectory === '') {
            throw new \InvalidArgumentException('Proxy directory MUST NOT be empty.');
        }

        if ($proxyDirectory === null) {
            $workingDirectory = \getcwd();

            if ($workingDirectory === false) {
                throw DoublesError::workingDirectoryUnresolved();
            }

            $proxyDirectory = \sprintf(
                '%s/greenlight-proxies-%s',
                \rtrim(\sys_get_temp_dir(), '/'),
                \substr(\sha1($workingDirectory), 0, 12),
            );
        }

        $this->generator = new ProxyGenerator($proxyDirectory);
        $this->renderer = new ValueRenderer();
        $this->doubles = new \WeakMap();
    }

    /**
     * Creates a strict double. Verification checks each planned expectation
     * at test end. A call without an expectation fails the test immediately.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     * @param \Closure(MockPlan<T>): void|null $plan
     *
     * @return T
     */
    public function mock(string $type, ?\Closure $plan = null): object
    {
        return $this->create($type, DoubleKind::Mock, $plan);
    }

    /**
     * Creates an inert double that satisfies the specified type. All
     * interactions cause a test error. Use a mock with explicit expectations
     * when a collaborator must supply results.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    public function stub(string $type): object
    {
        return $this->create($type, DoubleKind::Stub, null);
    }

    /**
     * Creates a spy that records each call and its arguments. A call to a
     * method that returns a value causes a test error. Use `callsTo()` to get the
     * calls. Use `Expect` to check them.
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
     * Gets the calls to one method of a double from this factory. The result
     * uses call order. Each entry contains the arguments for one call. The
     * method must exist on the doubled type.
     *
     * @return list<list<mixed>>
     */
    public function callsTo(object $double, string $method): array
    {
        if (!isset($this->doubles[$double])) {
            throw DoublesError::foreignDouble($double::class);
        }

        $state = $this->doubles[$double];
        $reflection = new \ReflectionClass($state->type);

        if (!$reflection->hasMethod($method)) {
            throw DoublesError::noSuchRecordedMethod($state->type, $method);
        }

        $method = $reflection->getMethod($method)->getName();

        return $state->recordedCalls[$method] ?? [];
    }

    /**
     * Verifies mocks and clears their state when the test scope closes.
     * One `ExpectationFailed` contains the details for all unmet expectations.
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
                ExpectationCounter::increment();

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
     * @param \Closure(MockPlan<T>): void|null $plan
     *
     * @return T
     */
    private function create(string $type, DoubleKind $kind, ?\Closure $plan): object
    {
        $state = new DoubleState($type, $kind);

        if ($plan instanceof \Closure) {
            $plan(new MockPlan($state, $type));
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
        $recorded = $state->recordedCalls[$expectation->method] ?? [];
        $actual = $recorded === []
            ? 'never called'
            : \implode("\n", \array_map(
                fn(array $arguments): string => MethodExpectation::renderCall($this->renderer, $expectation->method, $arguments),
                $recorded,
            ));

        return new FailureDetail(
            \sprintf(
                'Calls to %s::%s(): %s. The expectation requires %s.',
                $state->type,
                $expectation->method,
                MethodExpectation::timesPhrase($expectation->actualCalls),
                $expectation->describeExpectedCount(),
            ),
            $expectation->describePlan($this->renderer),
            $actual,
        );
    }
}
