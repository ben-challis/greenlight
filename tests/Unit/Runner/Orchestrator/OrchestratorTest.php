<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestStarted;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\Orchestrator;
use Greenlight\Runner\Protocol\Message;
use Greenlight\Runner\Protocol\Messages\AttemptStarted;
use Greenlight\Runner\Protocol\Messages\EventEnvelope;
use Greenlight\Runner\Protocol\Messages\Fatal;
use Greenlight\Runner\Protocol\Messages\Ready;
use Greenlight\Runner\Protocol\Messages\Recycling;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Worker\WorkerError;
use Greenlight\Tests\Fixture\CrashDiagnostics\CrashDiagnosticsTest;
use Greenlight\Tests\Fixture\CrashUnicodeDiagnostics\CrashUnicodeDiagnosticsTest;
use Greenlight\Tests\Fixture\LeakSuite\CleanTest;
use Greenlight\Tests\Fixture\Lifecycle\Bail\AaTest;
use Greenlight\Tests\Fixture\Lifecycle\Bail\BbTest;
use Greenlight\Tests\Fixture\ResourceScheduling\SlowResourceTest;
use Greenlight\Tests\Fixture\ResourceScheduling\WaitingResourceTest;
use Greenlight\Tests\Fixture\Runner\Orchestrator\DisconnectBeforeAssignmentWorker;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\NativeOrchestrator;
use Greenlight\Tests\Support\ScriptedWorkerTransport;

final class OrchestratorTest
{
    #[Test]
    #[Timeout(30.0)]
    public function aSpawnedWorkerThatNeverConnectsFailsTheRunInsteadOfHangingIt(): void
    {
        // This process remains active but does not connect to the orchestrator
        // socket. It represents a worker that cannot complete interpreter
        // startup on a machine without available resources.
        $orchestrator = NativeOrchestrator::create(
            workerCommand: [\PHP_BINARY, '-r', 'fwrite(STDERR, "booting, honest"); sleep(60);'],
            workingDirectory: \sys_get_temp_dir(),
            connectDeadlineSeconds: 0.5,
        );

        Expect::that(fn(): ResultSummary => $orchestrator->run($this->plan(), new CollectingEventSink(), 1))->because('a spawned worker that never connects fails the run instead of hanging it')
            ->toThrow(ProtocolError::class, '/did not connect within 0\.5 seconds/');
    }

    #[Test]
    public function aWorkerProcessThatCannotStartFailsWithItsSystemDiagnostic(): void
    {
        $missingDirectory = \sys_get_temp_dir()
            . '/greenlight-missing-' . \bin2hex(\random_bytes(8));
        $orchestrator = NativeOrchestrator::create(
            workerCommand: [\PHP_BINARY, 'bin/greenlight'],
            workingDirectory: $missingDirectory,
        );

        Expect::that(
            fn(): ResultSummary => $orchestrator->run(
                $this->plan(),
                new CollectingEventSink(),
                1,
            ),
        )
            ->because('a failed worker start MUST preserve the system diagnostic')
            ->toThrow(
                ProtocolError::class,
                '/Greenlight did not start a worker process: .+/',
            );
    }

    #[Test]
    #[Timeout(30.0)]
    public function anInvalidHelloTokenIsRejectedBeforeALegitimateWorkerCompletesThePlan(): void
    {
        $root = \dirname(__DIR__, 4);
        $script = \sprintf(
            <<<'PHP'
                [, , $address, $workerId, $token] = $argv;
                $socket = stream_socket_client($address);
                $json = json_encode([
                    'v' => 2,
                    't' => 'hello',
                    'p' => [
                        'workerId' => $workerId,
                        'token' => 'incorrect-' . $token,
                        'pid' => getmypid(),
                    ],
                ], JSON_THROW_ON_ERROR);
                fwrite($socket, pack('N', strlen($json)) . $json);
                fflush($socket);
                fclose($socket);

                require %s;

                exit(new \Greenlight\Runner\Worker\WorkerProcess()->run($address, $workerId, $token));
                PHP,
            \var_export($root . '/vendor/autoload.php', true),
        );
        $orchestrator = NativeOrchestrator::create(
            workerCommand: [\PHP_BINARY, '-r', $script],
            workingDirectory: $root,
        );
        $sink = new CollectingEventSink();

        $summary = $orchestrator->run($this->passingPlan(), $sink, 1);
        $results = $sink->results();

        Expect::that($summary->passed)
            ->because('a legitimate worker MUST complete the plan after an invalid hello token')
            ->toBe(1);
        Expect::that($summary->isSuccessful())->toBeTrue();
        Expect::that($results)->toHaveCount(1);
        Expect::that((string) $results[0]->id)
            ->toBe(CleanTest::class . '::passesAndIsCollectable');
    }

    #[Test]
    #[Timeout(30.0)]
    public function aWorkerThatDisconnectsBeforeAssignmentIsReplaced(): void
    {
        $root = \dirname(__DIR__, 4);
        $bootstrap = \sprintf(
            'require %s; exit(%s::run($argv[2], $argv[3], $argv[4]));',
            \var_export($root . '/vendor/autoload.php', true),
            DisconnectBeforeAssignmentWorker::class,
        );
        $orchestrator = NativeOrchestrator::create(
            workerCommand: [\PHP_BINARY, '-r', $bootstrap],
            workingDirectory: $root,
        );
        $sink = new CollectingEventSink();

        $summary = $orchestrator->run($this->passingPlan(), $sink, 1);
        $workers = [];

        foreach ($sink->events as $event) {
            if ($event instanceof TestClassStarted) {
                $workers[] = $event->workerId;
            }
        }

        Expect::that($summary->passed)
            ->because('a replacement worker MUST complete the undelivered assignment')
            ->toBe(1);
        Expect::that($workers)
            ->because('the disconnected worker MUST NOT start the test class')
            ->toBe(['w-2']);
    }

    #[Test]
    #[Timeout(30.0)]
    public function aConnectedWorkerThatGoesSilentBeforeStartingItsAssignmentFailsTheRun(): void
    {
        // A worker that completes the hello handshake, receives bootstrap,
        // then goes silent without ever reporting Ready.
        // No test is in flight, so per-test timeouts never fire, and the channel
        // stays open, so crash detection never fires either.
        $script = <<<'PHP'
            [, , $address, $workerId, $token] = $argv;
            $socket = stream_socket_client($address);
            $json = json_encode(['v' => 2, 't' => 'hello', 'p' => ['workerId' => $workerId, 'token' => $token, 'pid' => getmypid()]]);
            fwrite($socket, pack('N', strlen($json)) . $json);
            fflush($socket);
            sleep(60);
            PHP;

        $orchestrator = NativeOrchestrator::create(
            workerCommand: [\PHP_BINARY, '-r', $script],
            workingDirectory: \sys_get_temp_dir(),
            progressDeadlineSeconds: 0.5,
        );

        Expect::that(fn(): ResultSummary => $orchestrator->run($this->plan(), new CollectingEventSink(), 1))->because('a connected worker that goes silent before starting its assignment fails the run')
            ->toThrow(ProtocolError::class, '/sent no message for 0\.5 seconds/');
    }

    /** @param list<Message> $messages */
    #[Test]
    #[DataSet('unexpectedAttempts')]
    #[Timeout(30.0)]
    public function unexpectedAttemptMessagesNameTheProtocolDrift(array $messages, string $expectedDiagnostic): void
    {
        $transport = new ScriptedWorkerTransport([[new Ready(), ...$messages]]);
        $orchestrator = new Orchestrator($transport);

        Expect::that(fn(): ResultSummary => $orchestrator->run($this->plan(), new CollectingEventSink(), 1))->because('unexpected attempt messages name the protocol drift')
            ->toThrow(
                ProtocolError::class,
                matching: '/' . \preg_quote($expectedDiagnostic, '/') . '$/',
            );
    }

    /**
     * @return iterable<string, array{list<Message>, string}>
     */
    public static function unexpectedAttempts(): iterable
    {
        $id = new TestId('Example\\NeverExecutedTest', 'irrelevant');

        yield 'no active test' => [
            [new AttemptStarted($id, 1)],
            'reported attempt 1 for "Example\\NeverExecutedTest::irrelevant". '
            . 'Greenlight expected attempt 1. Active test: none.',
        ];

        yield 'attempt number jumps' => [
            [
                new EventEnvelope(new TestStarted($id, 1.0)),
                new AttemptStarted($id, 2),
            ],
            'reported attempt 2 for "Example\\NeverExecutedTest::irrelevant". '
            . 'Greenlight expected attempt 1. Active test: "Example\\NeverExecutedTest::irrelevant".',
        ];
    }

    #[Test]
    #[Timeout(30.0)]
    public function aWorkerFatalMessageFailsTheRunWithItsDiagnostic(): void
    {
        $transport = new ScriptedWorkerTransport([[
            new Fatal(new ThrowableDetail(
                \RuntimeException::class,
                'fixture worker failed',
                '/fixture/worker.php',
                42,
            )),
        ]]);
        $orchestrator = new Orchestrator($transport);

        Expect::that(fn(): ResultSummary => $orchestrator->run($this->plan(), new CollectingEventSink(), 1))->because('a worker fatal message fails the run with its diagnostic')
            ->toThrow(
                ProtocolError::class,
                '/reported a fatal Greenlight error: fixture worker failed \(\/fixture\/worker\.php:42\)/',
            );
    }

    #[Test]
    #[Timeout(30.0)]
    public function aRecyclingWorkerWithAMismatchedSummaryFailsTheRun(): void
    {
        $orchestrator = $this->recyclingWorker([], new ResultSummary(passed: 1));

        Expect::that(
            fn(): ResultSummary => $orchestrator->run(
                $this->plan(),
                new CollectingEventSink(),
                1,
            ),
        )
            ->because('a recycling worker with a mismatched summary fails the run')
            ->toThrow(ProtocolError::class, '/reported a summary .* but its event stream totals/');
    }

    /**
     * @param list<TestId> $remaining
     */
    #[Test]
    #[DataSet('invalidRecyclingRemainders')]
    #[Timeout(30.0)]
    public function aRecyclingWorkerMustReportItsExactRemainder(
        array $remaining,
        string $reported,
    ): void {
        $orchestrator = $this->recyclingWorker($remaining, new ResultSummary());

        Expect::that(
            fn(): ResultSummary => $orchestrator->run(
                $this->plan(),
                new CollectingEventSink(),
                1,
            ),
        )
            ->because('a worker MUST report the exact unfinished assignment')
            ->toThrow(
                ProtocolError::class,
                message: 'Worker "w-1" reported remaining tests ' . $reported . '. '
                    . 'Greenlight expected [Example\NeverExecutedTest::irrelevant] from its active assignment.',
            );
    }

    /**
     * @return iterable<string, array{
     *     list<TestId>,
     *     string
     * }>
     */
    public static function invalidRecyclingRemainders(): iterable
    {
        yield 'unknown replacement' => [
            [new TestId('Example\UnknownTest', 'neverPlanned')],
            '[Example\UnknownTest::neverPlanned]',
        ];
        yield 'omitted assignment' => [[], '[]'];
    }

    #[Test]
    #[Timeout(30.0)]
    public function resourceWaitStartsANewProgressWindowBeforeAssignment(): void
    {
        $root = \dirname(__DIR__, 4);
        $orchestrator = NativeOrchestrator::create(
            workerCommand: [\PHP_BINARY, $root . '/bin/greenlight'],
            workingDirectory: $root,
            recycleAfterTests: 1,
            progressDeadlineSeconds: 0.5,
            resourceLimits: ['database' => 1],
        );

        $summary = $orchestrator->run($this->resourcePlan(), new CollectingEventSink(), 2);

        Expect::that($summary->passed)->because('resource wait starts a new progress window before assignment')->toBe(2);
        Expect::that($summary->isSuccessful())->because('resource wait starts a new progress window before assignment')->toBeTrue();
    }

    #[Test]
    #[Timeout(30.0)]
    public function cumulativeTestCountRecyclesAWorkerAfterSeparateAssignments(): void
    {
        $root = \dirname(__DIR__, 4);
        $sink = new CollectingEventSink();
        $orchestrator = NativeOrchestrator::create(
            workerCommand: [\PHP_BINARY, $root . '/bin/greenlight'],
            workingDirectory: $root,
            recycleAfterTests: 2,
        );

        $summary = $orchestrator->run($this->twoClassPassingPlan(), $sink, 1);
        $recycled = [];

        foreach ($sink->events as $event) {
            if ($event instanceof WorkerRecycled) {
                $recycled[] = $event;
            }
        }

        Expect::that($summary->passed)
            ->because('the worker completes both assignments before it reaches its cumulative test budget')
            ->toBe(2);
        Expect::that($recycled)
            ->because('the cumulative test budget recycles the worker after its second assignment')
            ->toHaveCount(1);
        Expect::that($recycled[0]->reason)
            ->toBe(RecycleReason::TestCount);
    }

    #[Test]
    #[Timeout(30.0)]
    public function failureLimitDrainsRemainingBatchedClasses(): void
    {
        $root = \dirname(__DIR__, 4);
        $sink = new CollectingEventSink();
        $orchestrator = NativeOrchestrator::create(
            workerCommand: [\PHP_BINARY, $root . '/bin/greenlight'],
            workingDirectory: $root,
            stopAfterFailures: 1,
        );

        $summary = $orchestrator->run(
            $this->failingThenPassingPlan(),
            $sink,
            1,
            [
                AaTest::class => 0.001,
                BbTest::class => 0.001,
            ],
        );
        $results = $sink->results();

        Expect::that($summary->total())
            ->because('the failure limit MUST stop before the remaining batched class runs')
            ->toBe(1);
        Expect::that($summary->errored)
            ->toBe(1);
        Expect::that($results)
            ->toHaveCount(1);
        Expect::that((string) $results[0]->id)
            ->toBe(AaTest::class . '::fails');
    }

    #[Test]
    #[Timeout(30.0)]
    public function workerRecyclingPreservesABatchedRemainder(): void
    {
        $root = \dirname(__DIR__, 4);
        $sink = new CollectingEventSink();
        $orchestrator = NativeOrchestrator::create(
            workerCommand: [\PHP_BINARY, $root . '/bin/greenlight'],
            workingDirectory: $root,
            recycleAfterTests: 1,
        );

        $summary = $orchestrator->run(
            $this->twoClassPassingPlan(),
            $sink,
            1,
            [
                CleanTest::class => 0.001,
                WaitingResourceTest::class => 0.001,
            ],
        );

        Expect::that($summary->passed)
            ->because('a replacement worker MUST complete the remainder of a batched assignment')
            ->toBe(2);
        Expect::that(\array_map(
            static fn(TestResult $result): string => (string) $result->id,
            $sink->results(),
        ))
            ->because('worker recycling MUST preserve class and test order')
            ->toBe([
                CleanTest::class . '::passesAndIsCollectable',
                WaitingResourceTest::class . '::runsAfterTheWait',
            ]);
    }

    #[Test]
    #[Timeout(30.0)]
    public function crashedWorkerPreservesCapturedDiagnosticsInTheSyntheticResult(): void
    {
        $root = \dirname(__DIR__, 4);
        $sink = new CollectingEventSink();
        $orchestrator = NativeOrchestrator::create(
            workerCommand: [\PHP_BINARY, $root . '/bin/greenlight'],
            workingDirectory: $root,
        );

        $summary = $orchestrator->run($this->crashDiagnosticsPlan(), $sink, 1);
        $results = $sink->results();

        Expect::that($summary->errored)
            ->because('a worker crash MUST produce one synthetic error result')
            ->toBe(1);
        Expect::that($results)
            ->toHaveCount(1);
        Expect::that($results[0]->error?->message)
            ->because('the synthetic error MUST preserve the worker diagnostic output')
            ->toBe(
                "Worker \"w-1\" crashed during this test: the worker process exited unexpectedly.\n"
                . "Worker output:\nThe worker emitted crash diagnostics.",
            );
        Expect::that($results[0]->error?->class)->toBe(WorkerError::class);
    }

    #[Test]
    #[Timeout(30.0)]
    public function crashedWorkerRequeuesLaterClassesFromABatchedAssignment(): void
    {
        $root = \dirname(__DIR__, 4);
        $sink = new CollectingEventSink();
        $orchestrator = NativeOrchestrator::create(
            workerCommand: [\PHP_BINARY, $root . '/bin/greenlight'],
            workingDirectory: $root,
        );

        $summary = $orchestrator->run(
            $this->crashThenPassingPlan(),
            $sink,
            1,
            [
                CrashDiagnosticsTest::class => 0.001,
                CleanTest::class => 0.001,
            ],
        );
        $results = $sink->results();

        Expect::that($summary->errored)->toBe(1);
        Expect::that($summary->passed)
            ->because('crash containment MUST requeue later classes in a batched assignment')
            ->toBe(1);
        Expect::that(\array_map(
            static fn(TestResult $result): string => (string) $result->id,
            $results,
        ))
            ->toBe([
                CrashDiagnosticsTest::class . '::writesDiagnosticsThenExits',
                CleanTest::class . '::passesAndIsCollectable',
            ]);
    }

    #[Test]
    #[Timeout(30.0)]
    public function crashedWorkerKeepsACompleteUnicodeDiagnosticTail(): void
    {
        $root = \dirname(__DIR__, 4);
        $sink = new CollectingEventSink();
        $orchestrator = NativeOrchestrator::create(
            workerCommand: [\PHP_BINARY, $root . '/bin/greenlight'],
            workingDirectory: $root,
        );

        $summary = $orchestrator->run($this->crashUnicodeDiagnosticsPlan(), $sink, 1);
        $results = $sink->results();

        Expect::that($summary->errored)
            ->because('a worker crash MUST produce one synthetic error result')
            ->toBe(1);
        Expect::that($results)
            ->because('a worker crash MUST produce one synthetic test result')
            ->toHaveCount(1);
        Expect::that($results[0]->error?->message)
            ->because('the diagnostic tail MUST contain only complete Unicode characters within its byte limit')
            ->toBe(
                "Worker \"w-1\" crashed during this test: the worker process exited unexpectedly.\n"
                . "Worker output:\n"
                . \str_repeat('y', 2046),
            );
    }

    private function plan(): ExecutionPlan
    {
        $id = new TestId('Example\NeverExecutedTest', 'irrelevant');

        return new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($id->class, $id->method)),
        ]);
    }

    /** @param list<TestId> $remaining */
    private function recyclingWorker(array $remaining, ResultSummary $summary): Orchestrator
    {
        $transport = new ScriptedWorkerTransport([[
            new Ready(),
            new Recycling(RecycleReason::TestCount, $remaining, $summary),
        ]]);

        return new Orchestrator($transport);
    }

    private function passingPlan(): ExecutionPlan
    {
        $id = new TestId(CleanTest::class, 'passesAndIsCollectable');

        return new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($id->class, $id->method)),
        ]);
    }

    private function resourcePlan(): ExecutionPlan
    {
        $slow = new TestId(SlowResourceTest::class, 'holdsTheResource');
        $waiting = new TestId(WaitingResourceTest::class, 'runsAfterTheWait');

        return new ExecutionPlan([
            new PlanEntry($slow, new TestMetadata($slow->class, $slow->method, resources: ['database'])),
            new PlanEntry($waiting, new TestMetadata($waiting->class, $waiting->method, resources: ['database'])),
        ]);
    }

    private function twoClassPassingPlan(): ExecutionPlan
    {
        $clean = new TestId(CleanTest::class, 'passesAndIsCollectable');
        $waiting = new TestId(WaitingResourceTest::class, 'runsAfterTheWait');

        return new ExecutionPlan([
            new PlanEntry($clean, new TestMetadata($clean->class, $clean->method)),
            new PlanEntry($waiting, new TestMetadata($waiting->class, $waiting->method)),
        ]);
    }

    private function failingThenPassingPlan(): ExecutionPlan
    {
        $failing = new TestId(AaTest::class, 'fails');
        $passing = new TestId(BbTest::class, 'wouldAlsoPass');

        return new ExecutionPlan([
            new PlanEntry($failing, new TestMetadata($failing->class, $failing->method)),
            new PlanEntry($passing, new TestMetadata($passing->class, $passing->method)),
        ]);
    }

    private function crashDiagnosticsPlan(): ExecutionPlan
    {
        $id = new TestId(CrashDiagnosticsTest::class, 'writesDiagnosticsThenExits');

        return new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($id->class, $id->method)),
        ]);
    }

    private function crashThenPassingPlan(): ExecutionPlan
    {
        $crash = new TestId(CrashDiagnosticsTest::class, 'writesDiagnosticsThenExits');
        $passing = new TestId(CleanTest::class, 'passesAndIsCollectable');

        return new ExecutionPlan([
            new PlanEntry($crash, new TestMetadata($crash->class, $crash->method)),
            new PlanEntry($passing, new TestMetadata($passing->class, $passing->method)),
        ]);
    }

    private function crashUnicodeDiagnosticsPlan(): ExecutionPlan
    {
        $id = new TestId(CrashUnicodeDiagnosticsTest::class, 'writesUnicodeDiagnosticsThenExits');

        return new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($id->class, $id->method)),
        ]);
    }
}
