<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Doubles\Fake;
use Greenlight\Execution\Plugin\WorkerPluginRuntime;
use Greenlight\Execution\Worker\Worker;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Harness\Disposable;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Plugin\BeforeTestSubscriber;
use Greenlight\Plugin\TestAttemptLifecycle;
use Greenlight\Plugin\TestAttemptRunner;
use Greenlight\Plugin\TestContext;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\Cleanup;
use Greenlight\Test\CleanupFailed;
use Greenlight\Test\DeadlineExceededError;
use Greenlight\Test\ExecutionPolicy;
use Greenlight\Test\SkipTest;
use Greenlight\Test\TestDefinition;
use Greenlight\Tests\Support\CollectingEventSink;

final class TestAttemptLifecycleTest
{
    #[Test]
    public function lifecycleBoundariesUseTheTestDeadlineAndPreserveCleanupOrder(): void
    {
        $trace = new AttemptLifecycleTrace();
        $result = $this->run($trace);

        Expect::that($result->outcome)->toBe(Outcome::Passed);
        Expect::that($trace->events)->toBe([
            'runner enter', 'enter', 'constructor', 'subscriber', 'before', 'body',
            'join', 'after', 'cleanup', 'disposal', 'release', 'runner exit',
        ]);
        Expect::that($trace->deadline)->not()->toBeNull();
        Expect::that($trace->deadline)->toBe($trace->expectationDeadline);
        Expect::that(ExpectationRuntime::deadline())->toBeNull();
    }

    #[Test]
    #[DataSet('deadlineStages')]
    public function wrappedDeadlinesFailEachAttemptStage(string $stage): void
    {
        $trace = new AttemptLifecycleTrace([$stage => new \RuntimeException('Operation wrapper.', previous: DeadlineExceededError::forTest())]);
        $result = $this->run($trace);

        Expect::that($result->outcome)->toBe(Outcome::Failed);
        Expect::that($result->error)->toBeNull();
        Expect::that($result->failures[0]->message)->toBe('The test time limit stopped an asynchronous operation.');
        Expect::that($trace->events)->toContain('join')->toContain('cleanup')->toContain('release')->toContain('runner exit');
        Expect::that(ExpectationRuntime::deadline())->toBeNull();
    }

    /** @return iterable<string, array{string}> */
    public static function deadlineStages(): iterable
    {
        foreach (['constructor', 'subscriber', 'before', 'body', 'join', 'after', 'cleanup', 'disposal', 'release'] as $stage) {
            yield $stage => [$stage];
        }
    }

    #[Test]
    public function failedJoinPreservesThePrimaryFailureAndRunsAllCleanupStages(): void
    {
        $trace = new AttemptLifecycleTrace([
            'body' => ExpectationFailed::fromDetail(new FailureDetail('The body failed first.')),
            'join' => new \RuntimeException('Child operation failed.'),
            'cleanup' => DeadlineExceededError::forTest(),
        ]);
        $result = $this->run($trace);

        Expect::that($result->outcome)->toBe(Outcome::Failed);
        Expect::that($result->error)->toBeNull();
        Expect::that(\array_map(static fn(FailureDetail $detail): string => $detail->message, $result->failures))->toBe([
            'The body failed first.',
            'Test body cleanup caused an error: Child operation failed.',
            'The test time limit stopped an asynchronous operation.',
        ]);
        Expect::that(\array_slice($trace->events, -6))->toBe(['join', 'after', 'cleanup', 'disposal', 'release', 'runner exit']);
    }

    #[Test]
    public function deadlineDuringCleanupPreservesAnEarlierError(): void
    {
        $trace = new AttemptLifecycleTrace([
            'body' => new \LogicException('The body failed first.'),
            'after' => DeadlineExceededError::forTest(),
            'disposal' => DeadlineExceededError::forTest(),
        ]);
        $result = $this->run($trace);

        Expect::that($result->outcome)->toBe(Outcome::Errored);
        Expect::that($result->error?->message)->toBe('The body failed first.');
        Expect::that($result->failures)->toHaveCount(2);
        Expect::that($result->failures[0]->message)->toBe('The test time limit stopped an asynchronous operation.');
        Expect::that($result->failures[1]->message)->toBe('The test time limit stopped an asynchronous operation.');
    }

    #[Test]
    public function lifecycleAfterFailuresPreserveEarlierBodyFailures(): void
    {
        $trace = new AttemptLifecycleTrace([
            'body' => ExpectationFailed::fromDetail(new FailureDetail('The body failed first.')),
            'after' => ExpectationFailed::fromDetail(new FailureDetail('The After hook failed.')),
        ]);
        $result = $this->run($trace);

        Expect::that($result->outcome)->toBe(Outcome::Failed);
        Expect::that($result->error)->toBeNull();
        Expect::that(\array_map(static fn(FailureDetail $detail): string => $detail->message, $result->failures))->toBe([
            'The body failed first.',
            'The After hook failed.',
        ]);
        Expect::that(\array_slice($trace->events, -6))->toBe(['join', 'after', 'cleanup', 'disposal', 'release', 'runner exit']);
    }

    #[Test]
    public function aggregateChildFailuresRetainAllDiagnosticsAndRunCleanup(): void
    {
        $trace = new AttemptLifecycleTrace([
            'body' => new \LogicException('The body failed first.'),
            'join' => CleanupFailed::fromFailures([
                ExpectationFailed::fromDetail(new FailureDetail('The first child cleanup failed.')),
                new \RuntimeException('The second child cleanup failed.'),
                DeadlineExceededError::forTest(),
            ]),
        ]);
        $result = $this->run($trace);

        Expect::that($result->outcome)->toBe(Outcome::Errored);
        Expect::that($result->error?->message)->toBe('The body failed first.');
        Expect::that(\array_map(static fn(FailureDetail $detail): string => $detail->message, $result->failures))->toBe([
            'The first child cleanup failed.',
            'Test body cleanup caused an error: The second child cleanup failed.',
            'The test time limit stopped an asynchronous operation.',
        ]);
        Expect::that(\array_slice($trace->events, -6))->toBe(['join', 'after', 'cleanup', 'disposal', 'release', 'runner exit']);
    }

    #[Test]
    public function aggregateChildTimeoutKeepsItsFailureClassification(): void
    {
        $trace = new AttemptLifecycleTrace([
            'join' => CleanupFailed::fromFailures([
                DeadlineExceededError::forTest(),
                new \RuntimeException('The second child cleanup failed.'),
            ]),
        ]);
        $result = $this->run($trace);

        Expect::that($result->outcome)->toBe(Outcome::Failed);
        Expect::that($result->error)->toBeNull();
        Expect::that(\array_map(static fn(FailureDetail $detail): string => $detail->message, $result->failures))->toBe([
            'The test time limit stopped an asynchronous operation.',
            'Test body cleanup caused an error: The second child cleanup failed.',
        ]);
    }

    #[Test]
    #[DataSet('constructorSignals')]
    public function constructorControlSignalsKeepTheirErrorClassification(bool $skip): void
    {
        $failure = $skip
            ? new SkipTest('The constructor stopped.')
            : ExpectationFailed::fromDetail(new FailureDetail('The constructor stopped.'));
        $trace = new AttemptLifecycleTrace(['constructor' => $failure]);
        $result = $this->run($trace);

        Expect::that($result->outcome)->toBe(Outcome::Errored);
        Expect::that($result->error?->message)->toBe('The constructor stopped.');
        Expect::that($result->skipReason)->toBeNull();
        Expect::that($trace->events)->not()->toContain('after');
    }

    /** @return iterable<string, array{bool}> */
    public static function constructorSignals(): iterable
    {
        yield 'skip' => [true];
        yield 'expectation' => [false];
    }

    private function run(AttemptLifecycleTrace $trace): TestResult
    {
        $plugins = WorkerPluginRuntime::fromPlugins([new AttemptLifecyclePlugin($trace)]);
        $definitions = [new ServiceDefinition(AttemptLifecycleTrace::class, Scope::PerTest, static fn(): AttemptLifecycleTrace => $trace)];
        $plan = new ExecutionPlan([new PlanEntry(new TestDefinition(
            AttemptLifecycleCase::class,
            'runs',
            execution: new ExecutionPolicy(timeoutSeconds: 10.0, noExpectations: true),
        ))]);
        $sink = new CollectingEventSink();
        new Worker($definitions, $plugins)->run($plan, $sink);

        return $sink->results()[0];
    }
}

final class AttemptLifecycleTrace implements Disposable, Fake
{
    /** @var list<string> */
    public array $events = [];
    public ?float $deadline = null;
    public ?float $expectationDeadline = null;

    /** @param array<string, \Throwable> $failures */
    public function __construct(private readonly array $failures = []) {}

    public function hit(string $stage): void
    {
        $this->events[] = $stage;

        if (isset($this->failures[$stage])) {
            throw $this->failures[$stage];
        }
    }

    #[\Override]
    public function dispose(): void
    {
        $this->hit('disposal');
    }
}

final readonly class AttemptLifecycleCase
{
    public function __construct(private AttemptLifecycleTrace $trace, Cleanup $cleanup)
    {
        $cleanup->defer(function (): void {
            $this->trace->hit('cleanup');
        });
        $this->trace->hit('constructor');
    }

    #[Before]
    public function before(): void
    {
        $this->trace->hit('before');
    }

    public function runs(): void
    {
        $this->trace->hit('body');
    }

    #[After]
    public function after(): void
    {
        $this->trace->hit('after');
    }
}

final readonly class AttemptLifecyclePlugin implements BeforeTestSubscriber, Fake, TestAttemptLifecycle, TestAttemptRunner
{
    public function __construct(private AttemptLifecycleTrace $trace) {}

    #[\Override]
    public function runTestAttempt(\Closure $attempt): mixed
    {
        $this->trace->hit('runner enter');

        try {
            return $attempt();
        } finally {
            $this->trace->hit('runner exit');
        }
    }

    #[\Override]
    public function enterTestAttempt(?float $deadline): void
    {
        $this->trace->deadline = $deadline;
        $this->trace->expectationDeadline = ExpectationRuntime::deadline();
        $this->trace->hit('enter');
    }

    #[\Override]
    public function leaveTestBody(): void
    {
        $this->trace->hit('join');
    }

    #[\Override]
    public function leaveTestAttempt(): void
    {
        $this->trace->hit('release');
    }

    #[\Override]
    public function beforeTest(TestContext $context): void
    {
        $this->trace->hit('subscriber');
    }
}
