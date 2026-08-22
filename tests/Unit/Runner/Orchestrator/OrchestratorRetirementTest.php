<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Reporting\Ticking;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\LeakSuite\CleanTest;
use Greenlight\Tests\Fixture\ResourceScheduling\SlowResourceTest;
use Greenlight\Tests\Fixture\ResourceScheduling\WaitingResourceTest;
use Greenlight\Tests\Fixture\Runner\Orchestrator\LoggedWorkerProcess;
use Greenlight\Tests\Fixture\Runner\Orchestrator\RecycleUntilProgressWorker;
use Greenlight\Tests\Fixture\Runner\Orchestrator\RetirementProgressTest;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\NativeOrchestrator;
use Greenlight\Tests\Support\PlanEntryFixture;

final readonly class OrchestratorRetirementTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private EnvironmentVariables $environment,
    ) {}

    #[Test]
    #[Timeout(10.0)]
    public function simultaneousRetirementsContinueReporterTicks(): void
    {
        $log = $this->tempDirectory->path() . '/simultaneous.jsonl';
        $this->environment->set('GREENLIGHT_RETIREMENT_LOG', $log);
        $this->environment->set('GREENLIGHT_RETIREMENT_DELAY_MICROSECONDS', '1000000');
        $ticker = new class implements Fake, Ticking {
            /** @var list<float> */
            public array $ticks = [];

            #[\Override]
            public function tick(float $now): void
            {
                $this->ticks[] = $now;
            }
        };
        $sink = new CollectingEventSink();
        $orchestrator = NativeOrchestrator::create(
            workerCommand: $this->workerCommand(LoggedWorkerProcess::class),
            workingDirectory: $this->repositoryRoot(),
            ticker: $ticker,
        );
        $plan = new ExecutionPlan([
            PlanEntryFixture::create(CleanTest::class, 'passesAndIsCollectable'),
            PlanEntryFixture::create(WaitingResourceTest::class, 'runsAfterTheWait'),
        ]);

        $summary = $orchestrator->run($plan, $sink, 2);
        $records = $this->records($log);
        $starts = $this->phaseTimes($records, 'exit-start');
        $ends = $this->phaseTimes($records, 'exit-end');

        if (\count($starts) !== 2 || \count($ends) !== 2) {
            Fail::because('Expected two complete worker retirement records.');
        }

        $ticksDuringExit = \array_filter(
            $ticker->ticks,
            static fn(float $tick): bool => $tick > \max($starts) && $tick < \min($ends),
        );

        Expect::that($summary->passed)
            ->because('both workers MUST complete their assignments before simultaneous retirement')
            ->toBe(2);
        Expect::that(\max($starts))
            ->because('both workers MUST be in retirement at the same time')
            ->toBeLessThan(\min($ends));
        Expect::that($ticksDuringExit)
            ->because('reporter ticks MUST continue while workers exit')
            ->not()->toBe([]);
    }

    #[Test]
    #[Timeout(10.0)]
    public function delayedRetirementDoesNotBlockAssignmentsToAnotherWorker(): void
    {
        $log = $this->tempDirectory->path() . '/progress.log';
        $marker = $this->tempDirectory->path() . '/progress.marker';
        $this->environment->set('GREENLIGHT_RETIREMENT_LOG', $log);
        $this->environment->set('GREENLIGHT_RETIREMENT_PROGRESS_MARKER', $marker);
        $sink = new CollectingEventSink();
        $orchestrator = NativeOrchestrator::create(
            workerCommand: $this->workerCommand(RecycleUntilProgressWorker::class),
            workingDirectory: $this->repositoryRoot(),
        );
        $plan = new ExecutionPlan([
            PlanEntryFixture::create(CleanTest::class, 'passesAndIsCollectable'),
            PlanEntryFixture::create(SlowResourceTest::class, 'holdsTheResource'),
            PlanEntryFixture::create(RetirementProgressTest::class, 'recordsProgress'),
        ]);

        $summary = $orchestrator->run($plan, $sink, 2);
        $workers = [];

        foreach ($sink->events as $event) {
            if ($event instanceof TestClassStarted) {
                $workers[] = $event->workerId;
            }
        }

        Expect::that($summary->passed)
            ->because('the worker pool MUST complete all assignments while the first worker exits')
            ->toBe(3);
        Expect::that(\trim((string) \file_get_contents($log)))
            ->because('the delayed worker MUST observe progress before it exits')
            ->toBe('progress-observed');
        Expect::that(\array_slice($workers, 0, 2))
            ->because('the active worker MUST receive queued work while the first worker exits')
            ->toBe(['w-2', 'w-2']);
        Expect::that($workers[2])
            ->because('the active worker or its bootstrapped replacement MAY receive requeued work')
            ->toBeOneOf('w-2', 'w-3');
    }

    #[Test]
    #[Timeout(30.0)]
    public function highIsolatedChurnReusesChannelsAfterExitAndPrunesHandles(): void
    {
        $log = $this->tempDirectory->path() . '/isolated.jsonl';
        $this->environment->set('GREENLIGHT_RETIREMENT_LOG', $log);
        $this->environment->set('GREENLIGHT_RETIREMENT_DELAY_MICROSECONDS', '10000');
        $orchestrator = NativeOrchestrator::create(
            workerCommand: $this->workerCommand(LoggedWorkerProcess::class),
            workingDirectory: $this->repositoryRoot(),
        );
        $entries = [];

        for ($index = 1; $index <= 12; ++$index) {
            $class = 'Greenlight\\Tests\\Fixture\\MissingIsolated' . $index;
            $id = new TestId($class, 'doesNotExist');
            $entries[] = new PlanEntry($id, new TestMetadata($class, $id->method, isolated: true));
        }

        $summary = $orchestrator->run(new ExecutionPlan($entries), new CollectingEventSink(), 1);
        $records = $this->records($log);
        $starts = \array_values(\array_filter($records, static fn(array $record): bool => $record['phase'] === 'start'));
        $ends = \array_values(\array_filter($records, static fn(array $record): bool => $record['phase'] === 'exit-end'));

        Expect::that($summary->errored)
            ->because('each isolated fixture MUST complete through a fresh worker')
            ->toBe(12);
        Expect::that($starts)
            ->because('isolated churn MUST start one worker for each scheduling unit')
            ->toHaveCount(12);
        Expect::that(\array_column($starts, 'channel'))
            ->because('a single-worker run MUST reuse its only channel')
            ->toBe(\array_fill(0, 12, '1'));
        $startCount = \count($starts);

        for ($index = 1; $index < $startCount; ++$index) {
            Expect::that($starts[$index]['at'])
                ->because('a channel MUST stay unavailable until its old process exits')
                ->toBeGreaterThanOrEqual($ends[$index - 1]['at']);
        }

        Expect::that($orchestrator->workerTimings())
            ->because('the orchestrator MUST retain one timing record for each reaped worker')
            ->toHaveCount(12);
    }

    /**
     * @param class-string $worker
     * @return non-empty-list<non-empty-string>
     */
    private function workerCommand(string $worker): array
    {
        $bootstrap = \sprintf(
            'require %s; exit(%s::run($argv[2], $argv[3], $argv[4]));',
            \var_export($this->repositoryRoot() . '/vendor/autoload.php', true),
            $worker,
        );

        return [\PHP_BINARY, '-r', $bootstrap];
    }

    /**
     * @return list<array{phase: string, worker: string, channel: string, at: float}>
     */
    private function records(string $path): array
    {
        $records = [];
        $lines = \file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            Fail::because('Expected the worker retirement log to be readable.');
        }

        foreach ($lines as $line) {
            /** @var array{phase: string, worker: string, channel: string, at: float} $record */
            $record = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param list<array{phase: string, worker: string, channel: string, at: float}> $records
     * @return list<float>
     */
    private function phaseTimes(array $records, string $phase): array
    {
        return \array_values(\array_map(
            static fn(array $record): float => $record['at'],
            \array_filter($records, static fn(array $record): bool => $record['phase'] === $phase),
        ));
    }

    private function repositoryRoot(): string
    {
        return \dirname(__DIR__, 4);
    }
}
