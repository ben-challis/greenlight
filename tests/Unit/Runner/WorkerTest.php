<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Runner\Worker\LeakDetector;
use Greenlight\Runner\Worker\Worker;
use Greenlight\Runner\Worker\WorkerBudget;
use Greenlight\Tests\Fixture\LeakSuite\LeakyTest;
use Greenlight\Tests\Fixture\Lifecycle\CleanupCallbacks\CleanupOrderProbe;
use Greenlight\Tests\Fixture\Lifecycle\CleanupRetries\CleanupRetriesTest;
use Greenlight\Tests\Fixture\Lifecycle\DisposeFails\FailingDisposalProbe;
use Greenlight\Tests\Fixture\Lifecycle\Injection\InjectedProbe;
use Greenlight\Tests\Fixture\Lifecycle\PerTestDisposeFails\FailingPerTestDisposal;
use Greenlight\Tests\Fixture\Lifecycle\Retries\RetriesTest;
use Greenlight\Tests\Fixture\Lifecycle\RetryFilter\RetryFilterTest;
use Greenlight\Tests\Fixture\Lifecycle\Services\ServiceProbe;
use Greenlight\Tests\Fixture\Lifecycle\Services\ServicesTest;
use Greenlight\Tests\Fixture\Lifecycle\TemporalRetry\TemporalRetryTest;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;
use Greenlight\Tests\Fixture\Lifecycle\VerifyOnDispose\VerifyingProbe;
use Greenlight\Tests\Fixture\Runner\OptionalConstructorProbe;
use Greenlight\Tests\Fixture\Runner\UnsupportedConstructorProbe;
use Greenlight\Tests\Support\CollectingEventSink;

final class WorkerTest
{
    #[Test]
    public function isolatedPlanEntryMarksTheClassEvent(): void
    {
        $id = new TestId(OptionalConstructorProbe::class, 'usesDeclaredDefault');
        $plan = new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($id->class, $id->method, isolated: true)),
        ]);
        $sink = new CollectingEventSink();

        new Worker($this->registry())->run($plan, $sink);

        $classEvents = \array_values(\array_filter(
            $sink->events,
            static fn(object $event): bool => $event instanceof TestClassStarted,
        ));

        Expect::that($classEvents)
            ->because('an isolated plan entry MUST mark its class event')
            ->toHaveCount(1);
        Expect::that($classEvents[0]->isolated)
            ->toBeTrue();
    }

    #[Test]
    public function lifecycleRunsInTheFrozenOrder(): void
    {
        TraceLog::drain();
        [, $results] = $this->runFixture('Order');

        Expect::that(TraceLog::drain())->because('lifecycle runs in the frozen order')
            ->toBe(['construct', 'before1', 'before2', 'test', 'after2', 'after1']);
        Expect::that($results[0]->outcome)->toBe(Outcome::Passed);
    }

    #[Test]
    public function failingBeforeHookSkipsTheMethodButRunsAfterHooks(): void
    {
        TraceLog::drain();
        [, $results] = $this->runFixture('BeforeFails');

        Expect::that(TraceLog::drain())->because('failing before hook skips the method but runs after hooks')->toBe(['before', 'after']);
        Expect::that($results[0]->outcome)->toBe(Outcome::Errored);
        Expect::that($results[0]->error?->message)->toBe('before broke');
    }

    #[Test]
    public function throwingAfterHookErrorsAPassingTest(): void
    {
        [, $results] = $this->runFixture('AfterFails');

        Expect::that($results[0]->outcome)->because('throwing after hook errors a passing test')->toBe(Outcome::Errored);
        Expect::that($results[0]->error?->message)->toBe('after broke');
    }

    #[Test]
    public function afterHooksContinueAfterACleanupFailure(): void
    {
        TraceLog::drain();
        [, $results] = $this->runFixture('AfterFailureContinues');

        Expect::that(TraceLog::drain())
            ->because('later cleanup MUST run after an earlier after-hook throws')
            ->toBe(['test', 'failing cleanup', 'final cleanup']);
        Expect::that($results[0]->outcome)
            ->toBe(Outcome::Errored);
        Expect::that($results[0]->error?->message)
            ->because('the first teardown error MUST remain primary')
            ->toBe('after broke');
    }

    #[Test]
    public function deferredCleanupRunsAfterHooksAndBeforeFixtureDisposal(): void
    {
        TraceLog::drain();
        $registry = $this->registry();
        $registry->register(new ServiceDefinition(
            CleanupOrderProbe::class,
            Scope::PerTest,
            static fn(): CleanupOrderProbe => new CleanupOrderProbe(),
        ));

        [, $results] = $this->runFixture('CleanupCallbacks', $registry);

        Expect::that(TraceLog::drain())
            ->because('deferred cleanup runs after hooks and before fixture disposal')
            ->toBe(['test', 'after', 'second cleanup', 'first cleanup', 'fixture disposal']);
        Expect::that($results[0]->outcome)->toBe(Outcome::Passed);
    }

    #[Test]
    public function cleanupFailuresPreserveEarlierErrorsAndOverrideSkips(): void
    {
        TraceLog::drain();
        [, $results] = $this->runFixture('CleanupFailures');
        $byMethod = [];

        foreach ($results as $result) {
            $byMethod[$result->id->method] = $result;
        }

        Expect::that($byMethod['passesBeforeCleanupFails']->outcome)
            ->because('a cleanup failure errors a passing test')
            ->toBe(Outcome::Errored);
        Expect::that($byMethod['passesBeforeCleanupFails']->error?->message)
            ->toBe('cleanup broke after pass');
        Expect::that($byMethod['passesBeforeCleanupExpectationFails']->outcome)
            ->because('an expectation failure during cleanup fails the test')
            ->toBe(Outcome::Failed);
        Expect::that($byMethod['passesBeforeCleanupExpectationFails']->error)
            ->toBeNull();
        Expect::that($byMethod['passesBeforeCleanupExpectationFails']->failures[0]->expected)
            ->toBe("'expected'");
        Expect::that($byMethod['passesBeforeCleanupExpectationFails']->failures[0]->actual)
            ->toBe("'actual'");
        Expect::that($byMethod['errorsBeforeCleanupFails']->outcome)
            ->because('the test error remains primary when cleanup also fails')
            ->toBe(Outcome::Errored);
        Expect::that($byMethod['errorsBeforeCleanupFails']->error?->message)
            ->toBe('test broke');
        Expect::that($byMethod['errorsBeforeCleanupFails']->failures[0]->message)
            ->toBe('Cleanup callback caused an error: cleanup broke after error');
        Expect::that($byMethod['skipsBeforeCleanupFails']->outcome)
            ->because('a cleanup failure overrides a skip')
            ->toBe(Outcome::Errored);
        Expect::that($byMethod['skipsBeforeCleanupFails']->skipReason)
            ->toBeNull();
        Expect::that($byMethod['skipsBeforeCleanupFails']->error?->message)
            ->toBe('cleanup broke after skip');
        Expect::that(TraceLog::drain())
            ->because('a cleanup failure MUST NOT prevent later callbacks')
            ->toBe(['first cleanup', 'failing cleanup', 'last cleanup']);
    }

    #[Test]
    public function eachRetryReceivesAFreshCleanupStack(): void
    {
        CleanupRetriesTest::$attempts = 0;
        TraceLog::drain();

        [, $results] = $this->runFixture('CleanupRetries');

        Expect::that($results[0]->outcome)
            ->because('each retry receives a fresh cleanup stack')
            ->toBe(Outcome::Passed);
        Expect::that($results[0]->attempts)
            ->toBe(2);
        Expect::that(TraceLog::drain())
            ->toBe(['test 1', 'cleanup 1', 'test 2', 'cleanup 2']);
    }

    #[Test]
    public function retriesUntilPassingAndRecordsAttempts(): void
    {
        RetriesTest::$attempts = 0;
        [, $results] = $this->runFixture('Retries');

        Expect::that($results[0]->outcome)->because('retries until passing and records attempts')->toBe(Outcome::Passed);
        Expect::that($results[0]->attempts)->toBe(3);
    }

    #[Test]
    public function retryOnlyOnDoesNotRetryOtherThrowables(): void
    {
        RetryFilterTest::$attempts = 0;
        [, $results] = $this->runFixture('RetryFilter');

        Expect::that($results[0]->outcome)->because('retry only on does not retry other throwables')->toBe(Outcome::Errored);
        Expect::that($results[0]->attempts)->toBe(1);
        Expect::that(RetryFilterTest::$attempts)->toBe(1);
    }

    #[Test]
    public function temporalExpectationsReceiveAFreshDeadlineOnRetry(): void
    {
        TemporalRetryTest::$attempts = 0;
        [, $results] = $this->runFixture('TemporalRetry');

        Expect::that($results[0]->outcome)->because('temporal expectations receive a fresh deadline on retry')->toBe(Outcome::Passed);
        Expect::that($results[0]->attempts)->toBe(2);
        Expect::that($results[0]->expectations)->toBe(1);
    }

    #[Test]
    public function timeoutFailsASlowTestAfterTheFact(): void
    {
        [, $results] = $this->runFixture('SlowTimeout');

        Expect::that($results[0]->outcome)->because('timeout fails a slow test after the fact')->toBe(Outcome::Failed);
        Expect::that($results[0]->failures[0]->message)->toContain('configured time limit');
    }

    #[Test]
    public function runtimeSkipSignalReportsSkippedWithTheReason(): void
    {
        [$summary, $results] = $this->runFixture('RuntimeSkip');

        Expect::that($summary->skipped)->because('runtime skip signal reports skipped with the reason')->toBe(1);
        Expect::that($results[0]->skipReason)->toBe('the fixture backend is unreachable');
    }

    #[Test]
    public function skipsRunNothingAndConditionsAreEvaluated(): void
    {
        TraceLog::drain();
        [$summary, $results] = $this->runFixture('Skips');

        $byMethod = [];

        foreach ($results as $result) {
            $byMethod[$result->id->method] = $result;
        }

        Expect::that($summary->skipped)->because('skips run nothing and conditions are evaluated')->toBe(2);
        Expect::that($summary->passed)->toBe(1);
        Expect::that($byMethod['skippedUnconditionally']->skipReason)->toBe('not today');
        Expect::that($byMethod['skippedByCondition']->skipReason)->toContain('NeverCondition');
        Expect::that(TraceLog::drain())->toBe(['construct', 'satisfied']);
    }

    #[Test]
    public function constructorInjectionResolvesRegisteredServices(): void
    {
        [$summary] = $this->runFixture('Injection');

        Expect::that($summary->passed)->because('constructor injection resolves registered services')->toBe(1);
    }

    #[Test]
    public function unknownConstructorDependenciesErrorTheTestNamingTheType(): void
    {
        [, $results] = $this->runFixture('UnknownDep');

        Expect::that($results[0]->outcome)->because('unknown constructor dependencies error the test naming the type')->toBe(Outcome::Errored);
        Expect::that($results[0]->error?->message)->toContain('SplStack');
    }

    #[Test]
    public function optionalBuiltInConstructorParametersUseTheirDefaults(): void
    {
        $id = new TestId(OptionalConstructorProbe::class, 'usesDeclaredDefault');
        $plan = new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($id->class, $id->method)),
        ]);
        $sink = new CollectingEventSink();

        new Worker($this->registry())->run($plan, $sink);

        $result = $sink->results()[0];

        Expect::that($result->outcome)
            ->because('optional built-in constructor parameters use their defaults')
            ->toBe(Outcome::Passed);
        Expect::that($result->expectations)
            ->toBe(1);
    }

    #[Test]
    public function unsupportedConstructorParametersErrorTheTestWithGuidance(): void
    {
        $id = new TestId(UnsupportedConstructorProbe::class, 'neverRuns');
        $plan = new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($id->class, $id->method)),
        ]);
        $sink = new CollectingEventSink();

        new Worker($this->registry())->run($plan, $sink);

        $result = $sink->results()[0];

        Expect::that($result->outcome)
            ->because('unsupported constructor parameters error the test with guidance')
            ->toBe(Outcome::Errored);
        Expect::that($result->error?->message)
            ->toBe(\sprintf(
                'Constructor parameter $value of "%s" has no resolvable type. '
                . 'A test constructor can declare only harness service types.',
                UnsupportedConstructorProbe::class,
            ));
    }

    #[Test]
    public function unloadablePlanClassErrorsItsEntryWithoutEscapingTheWorker(): void
    {
        $id = new TestId('Missing\ExampleTest', 'neverRuns');
        $plan = new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($id->class, $id->method)),
        ]);
        $sink = new CollectingEventSink();

        $outcome = new Worker($this->registry())->run($plan, $sink);
        $result = $sink->results()[0];

        Expect::that($outcome->summary->errored)
            ->because('an unloadable plan class MUST become a contained test error')
            ->toBe(1);
        Expect::that($result->outcome)
            ->toBe(Outcome::Errored);
        Expect::that($result->error?->message)
            ->toBe('This process cannot load test class "Missing\ExampleTest" from the execution plan.');
        Expect::that($sink->sequence())
            ->toBe([
                'TestClassStarted',
                'TestStarted',
                'TestFinished',
                'TestClassFinished',
            ]);
    }

    #[Test]
    public function dataSetArgumentsReachTheMethodPerKey(): void
    {
        [$summary, $results] = $this->runFixture('DataSets');

        $failed = \array_values(\array_filter(
            $results,
            static fn(TestResult $result): bool => $result->outcome === Outcome::Errored,
        ));

        Expect::that($summary->total())->because('data set arguments reach the method per key')->toBe(3);
        Expect::that($summary->passed)->toBe(2);
        Expect::that(\count($failed))->toBe(1);
        Expect::that($failed[0]->id->dataSetKey)->toBe('broken row');
    }

    #[Test]
    public function dataSetProvidersCanBeDeclaredOnAnotherClass(): void
    {
        [$summary] = $this->runFixture('ExternalDataSets');

        Expect::that($summary->total())->because('data set providers can be declared on another class')->toBe(2);
        Expect::that($summary->passed)->toBe(2);
    }

    #[Test]
    public function perClassServicesAreSharedAndDisposedAtClassClose(): void
    {
        ServiceProbe::reset();
        TraceLog::drain();

        $registry = $this->registry();
        $registry->register(new ServiceDefinition(ServiceProbe::class, Scope::PerClass, static fn(): ServiceProbe => new ServiceProbe()));

        $this->runFixture('Services', $registry);

        Expect::that(TraceLog::drain())->because('per class services are shared and disposed at class close')->toBe([
            'probe1:created',
            'probe1:touched',
            'probe1:touched',
            'probe1:disposed',
        ]);
    }

    #[Test]
    public function parallelClassesRejectPerClassHarnessServices(): void
    {
        $id = new TestId(ServicesTest::class, 'firstTouch');
        $plan = new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($id->class, $id->method, allowParallel: true)),
        ]);
        $registry = $this->registry();
        $registry->register(new ServiceDefinition(
            ServiceProbe::class,
            Scope::PerClass,
            static fn(): ServiceProbe => new ServiceProbe(),
        ));
        $sink = new CollectingEventSink();

        new Worker($registry)->run($plan, $sink);

        $result = $sink->results()[0];
        Expect::that($result->outcome)
            ->because('a parallel class MUST NOT silently receive one class scope for each split entry')
            ->toBe(Outcome::Errored);
        Expect::that($result->error?->message)->toBe(\sprintf(
            'Per-class harness service "%s", required by "%s", cannot be used by a class with #[AllowParallel]. '
            . 'Use a per-test service or remove #[AllowParallel].',
            ServiceProbe::class,
            $id->class,
        ));
    }

    #[Test]
    public function perTestServicesAreFreshPerTest(): void
    {
        ServiceProbe::reset();
        TraceLog::drain();

        $registry = $this->registry();
        $registry->register(new ServiceDefinition(ServiceProbe::class, Scope::PerTest, static fn(): ServiceProbe => new ServiceProbe()));

        $this->runFixture('Services', $registry);

        Expect::that(TraceLog::drain())->because('per test services are fresh per test')->toBe([
            'probe1:created',
            'probe1:touched',
            'probe1:disposed',
            'probe2:created',
            'probe2:touched',
            'probe2:disposed',
        ]);
    }

    #[Test]
    public function classScopeTeardownFailureIsAttributedToTheLastTest(): void
    {
        $registry = $this->registry();
        $registry->register(new ServiceDefinition(FailingDisposalProbe::class, Scope::PerClass, static fn(): FailingDisposalProbe => new FailingDisposalProbe()));

        [, $results] = $this->runFixture('DisposeFails', $registry);

        Expect::that($results[0]->outcome)->because('class scope teardown failure is attributed to the last test')->toBe(Outcome::Passed);
        Expect::that($results[1]->outcome)->toBe(Outcome::Errored);
        Expect::that($results[1]->error?->message)->toBe('disposal broke');
    }

    #[Test]
    public function perTestScopeTeardownFailureErrorsTheCurrentTest(): void
    {
        $registry = $this->registry();
        $registry->register(new ServiceDefinition(
            FailingPerTestDisposal::class,
            Scope::PerTest,
            static fn(): FailingPerTestDisposal => new FailingPerTestDisposal(),
        ));

        [, $results] = $this->runFixture('PerTestDisposeFails', $registry);

        Expect::that($results[0]->outcome)
            ->because('a per-test teardown failure is attributed to the current test')
            ->toBe(Outcome::Errored);
        Expect::that($results[0]->error?->message)
            ->toBe('per-test disposal broke');
    }

    #[Test]
    public function disposalExpectationFailuresFailTheTestWithDiffs(): void
    {
        $registry = $this->registry();
        $registry->register(new ServiceDefinition(
            VerifyingProbe::class,
            Scope::PerTest,
            static fn(): VerifyingProbe => new VerifyingProbe(),
        ));

        [, $results] = $this->runFixture('VerifyOnDispose', $registry);

        Expect::that($results[0]->outcome)->because('disposal expectation failures fail the test with diffs')->toBe(Outcome::Failed);
        Expect::that($results[0]->error)->toBeNull();
        Expect::that($results[0]->failures[0]->message)->toContain('2');
    }

    #[Test]
    public function bailStopsTheRunAfterTheThreshold(): void
    {
        [$summary] = $this->runFixture('Bail', stopAfterFailures: 1);

        Expect::that($summary->total())->because('bail stops the run after the threshold')->toBe(1);
        Expect::that($summary->errored)->toBe(1);
    }

    #[Test]
    public function outputIsCapturedPerTestAndAttachedToTheResult(): void
    {
        [, $results] = $this->runFixture('Captured');

        $byMethod = [];

        foreach ($results as $result) {
            $byMethod[$result->id->method] = $result;
        }

        $noisy = $byMethod['echoesAndFails'];
        $optedOut = $byMethod['optsOutOfCapture'];

        Expect::that($noisy->outcome)->because('output is captured per test and attached to the result')->toBe(Outcome::Errored);
        Expect::that($noisy->output?->stdout)->toContain('noisy diagnostic output');
        Expect::that($noisy->output?->diagnostics[0]->message)->toContain('old api');
        Expect::that($optedOut->output)->toBeNull();
    }

    #[Test]
    public function testCountBudgetStopsTheWorkerAndReportsTheRemainder(): void
    {
        $directory = \dirname(__DIR__, 2) . '/Fixture/Lifecycle/Bail';
        $plan = new TestDiscoverer()->discover([$directory]);
        $sink = new CollectingEventSink();

        $outcome = new Worker($this->registry())->run(
            $plan,
            $sink,
            budget: new WorkerBudget(maxTests: 1),
        );

        Expect::that($outcome->recycleReason)->because('test count budget stops the worker and reports the remainder')->toBe(RecycleReason::TestCount);
        Expect::that($outcome->summary->total())->toBe(1);
        Expect::that(\count($outcome->remaining))->toBe(2);
        Expect::that((string) $outcome->remaining[0])->toContain('AaTest::wouldPass');
    }

    #[Test]
    public function memoryBudgetStopsTheWorkerAndReportsTheRemainder(): void
    {
        $directory = \dirname(__DIR__, 2) . '/Fixture/Lifecycle/Bail';
        $plan = new TestDiscoverer()->discover([$directory]);
        $sink = new CollectingEventSink();

        $outcome = new Worker($this->registry())->run(
            $plan,
            $sink,
            budget: new WorkerBudget(maxMemoryBytes: 1),
        );

        Expect::that($outcome->recycleReason)
            ->because('memory budget stops the worker and reports the remainder')
            ->toBe(RecycleReason::Memory);
        Expect::that($outcome->summary->total())->toBe(1);
        Expect::that(\count($outcome->remaining))->toBe(2);
        Expect::that((string) $outcome->remaining[0])->toContain('AaTest::wouldPass');
    }

    #[Test]
    public function drainRequestStopsBetweenTests(): void
    {
        $directory = \dirname(__DIR__, 2) . '/Fixture/Lifecycle/Bail';
        $plan = new TestDiscoverer()->discover([$directory]);
        $sink = new CollectingEventSink();

        $outcome = new Worker($this->registry())->run(
            $plan,
            $sink,
            drainRequested: static fn(): bool => true,
        );

        Expect::that($outcome->drained)->because('drain request stops between tests')->toBeTrue();
        Expect::that($outcome->summary->total())->toBe(1);
        Expect::that($outcome->recycleReason)->toBeNull();
    }

    #[Test]
    public function leakDetectionNamesTheTestThatRetainedItsInstance(): void
    {
        LeakyTest::$retained = [];

        $directory = \dirname(__DIR__, 2) . '/Fixture/LeakSuite';
        $plan = new TestDiscoverer()->discover([$directory]);
        $sink = new CollectingEventSink();

        $outcome = new Worker($this->registry(), new PluginRegistry(), new LeakDetector())
            ->run($plan, $sink);

        $leakedIds = \array_map(static fn($id): string => (string) $id, $outcome->leaks);

        Expect::that($outcome->leaks)->because('leak detection names the test that retained its instance')->toHaveCount(1);
        Expect::that($leakedIds[0])->toContain('LeakyTest::passesButLeaksItself');

        LeakyTest::$retained = [];
    }

    #[Test]
    public function eventsBracketClassesAndTests(): void
    {
        $sink = new CollectingEventSink();
        $this->runFixture('Order', sink: $sink);

        Expect::that($sink->sequence())->because('events bracket classes and tests')->toBe([
            'TestClassStarted',
            'TestStarted',
            'TestFinished',
            'TestClassFinished',
        ]);
    }

    /**
     * @param list<Plugin> $plugins
     *
     * @return array{ResultSummary, list<TestResult>}
     */
    private function runFixture(
        string $case,
        ?HarnessRegistry $registry = null,
        ?int $stopAfterFailures = null,
        ?CollectingEventSink $sink = null,
        array $plugins = [],
    ): array {
        $directory = \dirname(__DIR__, 2) . '/Fixture/Lifecycle/' . $case;
        $plan = new TestDiscoverer()->discover([$directory]);
        $sink ??= new CollectingEventSink();

        $outcome = new Worker($registry ?? $this->registry(), PluginRegistry::forWorker($plugins))
            ->run($plan, $sink, $stopAfterFailures);

        return [$outcome->summary, $sink->results()];
    }

    private function registry(): HarnessRegistry
    {
        return new HarnessRegistry([
            new ServiceDefinition(InjectedProbe::class, Scope::PerTest, static fn(): InjectedProbe => new InjectedProbe()),
        ]);
    }
}
