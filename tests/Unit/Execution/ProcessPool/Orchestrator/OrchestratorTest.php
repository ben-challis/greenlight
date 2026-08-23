<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Event\TestClassStarted;
use Greenlight\Event\TestStarted;
use Greenlight\Execution\ProcessPool\Orchestrator\Orchestrator;
use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Execution\ProcessPool\Protocol\Messages\AttemptStarted;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Fatal;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Ready;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Execution\Worker\WorkerError;
use Greenlight\Expect\Expect;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\TestId;
use Greenlight\Tests\Fixture\CrashDiagnostics\CrashDiagnosticsTest;
use Greenlight\Tests\Fixture\CrashUnicodeDiagnostics\CrashUnicodeDiagnosticsTest;
use Greenlight\Tests\Fixture\Execution\ProcessPool\Orchestrator\DisconnectBeforeAssignmentWorker;
use Greenlight\Tests\Fixture\LeakSuite\CleanTest;
use Greenlight\Tests\Fixture\Lifecycle\Bail\AaTest;
use Greenlight\Tests\Fixture\Lifecycle\Bail\BbTest;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\NativeOrchestrator;
use Greenlight\Tests\Support\PhpSubprocess;
use Greenlight\Tests\Support\PlanEntryFixture;
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
            workerCommand: PhpSubprocess::command(['-r', 'fwrite(STDERR, "booting, honest"); sleep(60);']),
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
            workerCommand: PhpSubprocess::command(['bin/greenlight']),
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
        $root = \dirname(__DIR__, 5);
        $script = \sprintf(
            <<<'PHP'
                [, , $address, $workerId, $token] = $argv;
                $socket = stream_socket_client($address);
                $json = json_encode([
                    'v' => 4,
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

                exit(new \Greenlight\Execution\ProcessPool\Worker\WorkerProcess()->run($address, $workerId, $token));
                PHP,
            \var_export($root . '/vendor/autoload.php', true),
        );
        $orchestrator = NativeOrchestrator::create(
            workerCommand: PhpSubprocess::command(['-r', $script]),
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
        $root = \dirname(__DIR__, 5);
        $bootstrap = \sprintf(
            'require %s; exit(%s::run($argv[2], $argv[3], $argv[4]));',
            \var_export($root . '/vendor/autoload.php', true),
            DisconnectBeforeAssignmentWorker::class,
        );
        $orchestrator = NativeOrchestrator::create(
            workerCommand: PhpSubprocess::command(['-r', $bootstrap]),
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
            $json = json_encode(['v' => 4, 't' => 'hello', 'p' => ['workerId' => $workerId, 'token' => $token, 'pid' => getmypid()]]);
            fwrite($socket, pack('N', strlen($json)) . $json);
            fflush($socket);
            sleep(60);
            PHP;

        $orchestrator = NativeOrchestrator::create(
            workerCommand: PhpSubprocess::command(['-r', $script]),
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
    public function failureLimitDrainsRemainingBatchedClasses(): void
    {
        $root = \dirname(__DIR__, 5);
        $sink = new CollectingEventSink();
        $orchestrator = NativeOrchestrator::create(
            workerCommand: PhpSubprocess::command([$root . '/bin/greenlight']),
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
    public function crashedWorkerPreservesCapturedDiagnosticsInTheSyntheticResult(): void
    {
        $root = \dirname(__DIR__, 5);
        $sink = new CollectingEventSink();
        $orchestrator = NativeOrchestrator::create(
            workerCommand: PhpSubprocess::command([$root . '/bin/greenlight']),
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
            ->toBe('Worker "w-1" crashed during this test: the worker process exited unexpectedly.');
        Expect::that($results[0]->error?->class)->toBe(WorkerError::class);
        Expect::that($results[0]->output?->stderr)
            ->because('the synthetic result MUST preserve worker standard error')
            ->toBe("The worker emitted crash diagnostics.\n");
    }

    #[Test]
    #[Timeout(30.0)]
    public function crashedWorkerRequeuesLaterClassesFromABatchedAssignment(): void
    {
        $root = \dirname(__DIR__, 5);
        $sink = new CollectingEventSink();
        $orchestrator = NativeOrchestrator::create(
            workerCommand: PhpSubprocess::command([$root . '/bin/greenlight']),
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
    public function crashedWorkerKeepsCompleteUnicodeDiagnosticOutput(): void
    {
        $root = \dirname(__DIR__, 5);
        $sink = new CollectingEventSink();
        $orchestrator = NativeOrchestrator::create(
            workerCommand: PhpSubprocess::command([$root . '/bin/greenlight']),
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
            ->toBe('Worker "w-1" crashed during this test: the worker process exited unexpectedly.');
        Expect::that($results[0]->output?->stderr)
            ->because('captured standard error MUST contain complete Unicode characters')
            ->toBe('xx€' . \str_repeat('y', 2046));
        Expect::that($results[0]->output?->stderrTruncated)->toBeFalse();
    }

    private function plan(): ExecutionPlan
    {
        $id = new TestId('Example\NeverExecutedTest', 'irrelevant');

        return new ExecutionPlan([
            PlanEntryFixture::create($id->class, $id->method),
        ]);
    }

    private function passingPlan(): ExecutionPlan
    {
        $id = new TestId(CleanTest::class, 'passesAndIsCollectable');

        return new ExecutionPlan([
            PlanEntryFixture::create($id->class, $id->method),
        ]);
    }

    private function failingThenPassingPlan(): ExecutionPlan
    {
        $failing = new TestId(AaTest::class, 'fails');
        $passing = new TestId(BbTest::class, 'wouldAlsoPass');

        return new ExecutionPlan([
            PlanEntryFixture::create($failing->class, $failing->method),
            PlanEntryFixture::create($passing->class, $passing->method),
        ]);
    }

    private function crashDiagnosticsPlan(): ExecutionPlan
    {
        $id = new TestId(CrashDiagnosticsTest::class, 'writesDiagnosticsThenExits');

        return new ExecutionPlan([
            PlanEntryFixture::create($id->class, $id->method),
        ]);
    }

    private function crashThenPassingPlan(): ExecutionPlan
    {
        $crash = new TestId(CrashDiagnosticsTest::class, 'writesDiagnosticsThenExits');
        $passing = new TestId(CleanTest::class, 'passesAndIsCollectable');

        return new ExecutionPlan([
            PlanEntryFixture::create($crash->class, $crash->method),
            PlanEntryFixture::create($passing->class, $passing->method),
        ]);
    }

    private function crashUnicodeDiagnosticsPlan(): ExecutionPlan
    {
        $id = new TestId(CrashUnicodeDiagnosticsTest::class, 'writesUnicodeDiagnosticsThenExits');

        return new ExecutionPlan([
            PlanEntryFixture::create($id->class, $id->method),
        ]);
    }
}
