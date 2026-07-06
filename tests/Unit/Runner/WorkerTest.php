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
use Greenlight\Runner\Worker\Worker;
use Greenlight\Runner\Worker\WorkerBudget;
use Greenlight\Tests\Fixture\Lifecycle\DisposeFails\FailingDisposalProbe;
use Greenlight\Tests\Fixture\Lifecycle\Retries\RetriesTest;
use Greenlight\Tests\Fixture\Lifecycle\RetryFilter\RetryFilterTest;
use Greenlight\Tests\Fixture\Lifecycle\Services\ServiceProbe;
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

        new Expect()->that(TraceLog::drain())
            ->toBe(['construct', 'before1', 'before2', 'test', 'after2', 'after1'])
            ->and($results[0]->outcome)->toBe(Outcome::Passed);
    }

    #[Test]
    public function failingBeforeHookSkipsTheMethodButRunsAfterHooks(): void
    {
        TraceLog::drain();
        [, $results] = $this->runFixture('BeforeFails');

        new Expect()->that(TraceLog::drain())->toBe(['before', 'after'])
            ->and($results[0]->outcome)->toBe(Outcome::Errored)
            ->and($results[0]->error?->message)->toBe('before broke');
    }

    #[Test]
    public function throwingAfterHookErrorsAPassingTest(): void
    {
        [, $results] = $this->runFixture('AfterFails');

        new Expect()->that($results[0]->outcome)->toBe(Outcome::Errored)
            ->and($results[0]->error?->message)->toBe('after broke');
    }

    #[Test]
    public function retriesUntilPassingAndRecordsAttempts(): void
    {
        RetriesTest::$attempts = 0;
        [, $results] = $this->runFixture('Retries');

        new Expect()->that($results[0]->outcome)->toBe(Outcome::Passed)
            ->and($results[0]->attempts)->toBe(3);
    }

    #[Test]
    public function retryOnlyOnDoesNotRetryOtherThrowables(): void
    {
        RetryFilterTest::$attempts = 0;
        [, $results] = $this->runFixture('RetryFilter');

        new Expect()->that($results[0]->outcome)->toBe(Outcome::Errored)
            ->and($results[0]->attempts)->toBe(1)
            ->and(RetryFilterTest::$attempts)->toBe(1);
    }

    #[Test]
    public function timeoutFailsASlowTestAfterTheFact(): void
    {
        [, $results] = $this->runFixture('SlowTimeout');

        new Expect()->that($results[0]->outcome)->toBe(Outcome::Failed)
            ->and($results[0]->failures[0]->message)->toContain('Timed out');
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

        new Expect()->that($summary->skipped)->toBe(2)
            ->and($summary->passed)->toBe(1)
            ->and($byMethod['skippedUnconditionally']->skipReason)->toBe('not today')
            ->and($byMethod['skippedByCondition']->skipReason)->toContain('NeverCondition')
            ->and(TraceLog::drain())->toBe(['construct', 'satisfied']);
    }

    #[Test]
    public function constructorInjectionResolvesRegisteredServices(): void
    {
        [$summary] = $this->runFixture('Injection');

        new Expect()->that($summary->passed)->toBe(1);
    }

    #[Test]
    public function unknownConstructorDependenciesErrorTheTestNamingTheType(): void
    {
        [, $results] = $this->runFixture('UnknownDep');

        new Expect()->that($results[0]->outcome)->toBe(Outcome::Errored)
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

        new Expect()->that($summary->total())->toBe(3)
            ->and($summary->passed)->toBe(2)
            ->and(\count($failed))->toBe(1)
            ->and($failed[0]->id->dataSetKey)->toBe('broken row');
    }

    #[Test]
    public function perClassServicesAreSharedAndDisposedAtClassClose(): void
    {
        ServiceProbe::reset();
        TraceLog::drain();

        $registry = $this->registry();
        $registry->register(new ServiceDefinition(ServiceProbe::class, Scope::PerClass, static fn(): ServiceProbe => new ServiceProbe()));

        $this->runFixture('Services', $registry);

        new Expect()->that(TraceLog::drain())->toBe([
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

        new Expect()->that(TraceLog::drain())->toBe([
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

        new Expect()->that($results[0]->outcome)->toBe(Outcome::Passed)
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

        new Expect()->that($results[0]->outcome)->toBe(Outcome::Failed)
            ->and($results[0]->error)->toBeNull()
            ->and($results[0]->failures[0]->message)->toContain('2');
    }

    #[Test]
    public function bailStopsTheRunAfterTheThreshold(): void
    {
        [$summary] = $this->runFixture('Bail', stopAfterFailures: 1);

        new Expect()->that($summary->total())->toBe(1)
            ->and($summary->errored)->toBe(1);
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

        new Expect()->that($outcome->recycleReason)->toBe(RecycleReason::TestCount)
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

        new Expect()->that($outcome->drained)->toBeTrue()
            ->and($outcome->summary->total())->toBe(1)
            ->and($outcome->recycleReason)->toBeNull();
    }

    #[Test]
    public function eventsBracketClassesAndTests(): void
    {
        $sink = new CollectingEventSink();
        $this->runFixture('Order', sink: $sink);

        new Expect()->that($sink->sequence())->toBe([
            'TestClassStarted',
            'TestStarted',
            'TestFinished',
            'TestClassFinished',
        ]);
    }

    /**
     * @return array{ResultSummary, list<TestResult>}
     */
    private function runFixture(
        string $case,
        ?HarnessRegistry $registry = null,
        ?int $stopAfterFailures = null,
        ?CollectingEventSink $sink = null,
    ): array {
        $directory = \dirname(__DIR__, 2) . '/Fixture/Lifecycle/' . $case;
        $plan = new TestDiscoverer()->discover([$directory]);
        $sink ??= new CollectingEventSink();

        $outcome = new Worker($registry ?? $this->registry())->run($plan, $sink, $stopAfterFailures);

        return [$outcome->summary, $sink->results()];
    }

    private function registry(): HarnessRegistry
    {
        return new HarnessRegistry([
            new ServiceDefinition(Expect::class, Scope::PerTest, static fn(): Expect => new Expect()),
        ]);
    }
}
