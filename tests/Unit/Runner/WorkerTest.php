<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
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
use Greenlight\Tests\Fixture\Lifecycle\DisposeFails\FailingDisposalProbe;
use Greenlight\Tests\Fixture\Lifecycle\Injection\InjectedProbe;
use Greenlight\Tests\Fixture\Lifecycle\Retries\RetriesTest;
use Greenlight\Tests\Fixture\Lifecycle\RetryFilter\RetryFilterTest;
use Greenlight\Tests\Fixture\Lifecycle\Services\ServiceProbe;
use Greenlight\Tests\Fixture\Lifecycle\TemporalRetry\TemporalRetryTest;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;
use Greenlight\Tests\Fixture\Lifecycle\VerifyOnDispose\VerifyingProbe;
use Greenlight\Tests\Support\CollectingEventSink;

final class WorkerTest
{
    #[Test]
    public function lifecycleRunsInTheFrozenOrder(): void
    {
        TraceLog::drain();
        [, $results] = $this->runFixture('Order');

        Expect::that(TraceLog::drain())->because('lifecycle runs in the frozen order')
            ->toBe(['construct', 'before1', 'before2', 'test', 'after2', 'after1'])
            ->and($results[0]->outcome)->toBe(Outcome::Passed);
    }

    #[Test]
    public function failingBeforeHookSkipsTheMethodButRunsAfterHooks(): void
    {
        TraceLog::drain();
        [, $results] = $this->runFixture('BeforeFails');

        Expect::that(TraceLog::drain())->because('failing before hook skips the method but runs after hooks')->toBe(['before', 'after'])
            ->and($results[0]->outcome)->toBe(Outcome::Errored)
            ->and($results[0]->error?->message)->toBe('before broke');
    }

    #[Test]
    public function throwingAfterHookErrorsAPassingTest(): void
    {
        [, $results] = $this->runFixture('AfterFails');

        Expect::that($results[0]->outcome)->because('throwing after hook errors a passing test')->toBe(Outcome::Errored)
            ->and($results[0]->error?->message)->toBe('after broke');
    }

    #[Test]
    public function retriesUntilPassingAndRecordsAttempts(): void
    {
        RetriesTest::$attempts = 0;
        [, $results] = $this->runFixture('Retries');

        Expect::that($results[0]->outcome)->because('retries until passing and records attempts')->toBe(Outcome::Passed)
            ->and($results[0]->attempts)->toBe(3);
    }

    #[Test]
    public function retryOnlyOnDoesNotRetryOtherThrowables(): void
    {
        RetryFilterTest::$attempts = 0;
        [, $results] = $this->runFixture('RetryFilter');

        Expect::that($results[0]->outcome)->because('retry only on does not retry other throwables')->toBe(Outcome::Errored)
            ->and($results[0]->attempts)->toBe(1)
            ->and(RetryFilterTest::$attempts)->toBe(1);
    }

    #[Test]
    public function temporalExpectationsReceiveAFreshDeadlineOnRetry(): void
    {
        TemporalRetryTest::$attempts = 0;
        [, $results] = $this->runFixture('TemporalRetry');

        Expect::that($results[0]->outcome)->because('temporal expectations receive a fresh deadline on retry')->toBe(Outcome::Passed)
            ->and($results[0]->attempts)->toBe(2)
            ->and($results[0]->expectations)->toBe(1);
    }

    #[Test]
    public function timeoutFailsASlowTestAfterTheFact(): void
    {
        [, $results] = $this->runFixture('SlowTimeout');

        Expect::that($results[0]->outcome)->because('timeout fails a slow test after the fact')->toBe(Outcome::Failed)
            ->and($results[0]->failures[0]->message)->toContain('configured time limit');
    }

    #[Test]
    public function runtimeSkipSignalReportsSkippedWithTheReason(): void
    {
        [$summary, $results] = $this->runFixture('RuntimeSkip');

        Expect::that($summary->skipped)->because('runtime skip signal reports skipped with the reason')->toBe(1)
            ->and($results[0]->skipReason)->toBe('the fixture backend is unreachable');
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

        Expect::that($summary->skipped)->because('skips run nothing and conditions are evaluated')->toBe(2)
            ->and($summary->passed)->toBe(1)
            ->and($byMethod['skippedUnconditionally']->skipReason)->toBe('not today')
            ->and($byMethod['skippedByCondition']->skipReason)->toContain('NeverCondition')
            ->and(TraceLog::drain())->toBe(['construct', 'satisfied']);
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

        Expect::that($results[0]->outcome)->because('unknown constructor dependencies error the test naming the type')->toBe(Outcome::Errored)
            ->and($results[0]->error?->message)->toContain('SplStack');
    }

    #[Test]
    public function dataSetArgumentsReachTheMethodPerKey(): void
    {
        [$summary, $results] = $this->runFixture('DataSets');

        $failed = \array_values(\array_filter(
            $results,
            static fn(TestResult $result): bool => $result->outcome === Outcome::Errored,
        ));

        Expect::that($summary->total())->because('data set arguments reach the method per key')->toBe(3)
            ->and($summary->passed)->toBe(2)
            ->and(\count($failed))->toBe(1)
            ->and($failed[0]->id->dataSetKey)->toBe('broken row');
    }

    #[Test]
    public function dataSetProvidersCanBeDeclaredOnAnotherClass(): void
    {
        [$summary] = $this->runFixture('ExternalDataSets');

        Expect::that($summary->total())->because('data set providers can be declared on another class')->toBe(2)
            ->and($summary->passed)->toBe(2);
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

        Expect::that($results[0]->outcome)->because('class scope teardown failure is attributed to the last test')->toBe(Outcome::Passed)
            ->and($results[1]->outcome)->toBe(Outcome::Errored)
            ->and($results[1]->error?->message)->toBe('disposal broke');
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

        Expect::that($results[0]->outcome)->because('disposal expectation failures fail the test with diffs')->toBe(Outcome::Failed)
            ->and($results[0]->error)->toBeNull()
            ->and($results[0]->failures[0]->message)->toContain('2');
    }

    #[Test]
    public function bailStopsTheRunAfterTheThreshold(): void
    {
        [$summary] = $this->runFixture('Bail', stopAfterFailures: 1);

        Expect::that($summary->total())->because('bail stops the run after the threshold')->toBe(1)
            ->and($summary->errored)->toBe(1);
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

        Expect::that($noisy->outcome)->because('output is captured per test and attached to the result')->toBe(Outcome::Errored)
            ->and($noisy->output?->stdout)->toContain('noisy diagnostic output')
            ->and($noisy->output?->diagnostics[0]->message)->toContain('old api')
            ->and($optedOut->output)->toBeNull();
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

        Expect::that($outcome->recycleReason)->because('test count budget stops the worker and reports the remainder')->toBe(RecycleReason::TestCount)
            ->and($outcome->summary->total())->toBe(1)
            ->and(\count($outcome->remaining))->toBe(2)
            ->and((string) $outcome->remaining[0])->toContain('AaTest::wouldPass');
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

        Expect::that($outcome->drained)->because('drain request stops between tests')->toBeTrue()
            ->and($outcome->summary->total())->toBe(1)
            ->and($outcome->recycleReason)->toBeNull();
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

        Expect::that($outcome->leaks)->because('leak detection names the test that retained its instance')->toHaveCount(1)
            ->and($leakedIds[0])->toContain('LeakyTest::passesButLeaksItself');

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
