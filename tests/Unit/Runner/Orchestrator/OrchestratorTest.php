<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\Orchestrator;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Tests\Fixture\ResourceScheduling\SlowResourceTest;
use Greenlight\Tests\Fixture\ResourceScheduling\WaitingResourceTest;
use Greenlight\Tests\Support\CollectingEventSink;

final class OrchestratorTest
{
    #[Test]
    #[Timeout(30.0)]
    public function aSpawnedWorkerThatNeverConnectsFailsTheRunInsteadOfHangingIt(): void
    {
        // This process remains active but does not connect to the orchestrator
        // socket. It represents a worker that cannot complete interpreter
        // startup on a machine without available resources.
        $orchestrator = new Orchestrator(
            workerCommand: [\PHP_BINARY, '-r', 'fwrite(STDERR, "booting, honest"); sleep(60);'],
            workingDirectory: \sys_get_temp_dir(),
            connectDeadlineSeconds: 0.5,
        );

        Expect::that(fn(): ResultSummary => $orchestrator->run($this->plan(), new CollectingEventSink(), 1))->because('a spawned worker that never connects fails the run instead of hanging it')
            ->toThrow(ProtocolError::class, '/did not connect within 0\.5 seconds/');
    }

    #[Test]
    #[Timeout(30.0)]
    public function aConnectedWorkerThatGoesSilentBeforeStartingItsAssignmentFailsTheRun(): void
    {
        // This worker completes the hello handshake and receives an assignment.
        // It then stops communication before it reports TestStarted. No test is
        // active, so a test timeout does not occur. The open channel also
        // prevents crash detection.
        $script = <<<'PHP'
            [, , $address, $workerId, $token] = $argv;
            $socket = stream_socket_client($address);
            $json = json_encode(['v' => 1, 't' => 'hello', 'p' => ['workerId' => $workerId, 'token' => $token, 'pid' => getmypid()]]);
            fwrite($socket, pack('N', strlen($json)) . $json);
            fflush($socket);
            sleep(60);
            PHP;

        $orchestrator = new Orchestrator(
            workerCommand: [\PHP_BINARY, '-r', $script],
            workingDirectory: \sys_get_temp_dir(),
            progressDeadlineSeconds: 0.5,
        );

        Expect::that(fn(): ResultSummary => $orchestrator->run($this->plan(), new CollectingEventSink(), 1))->because('a connected worker that goes silent before starting its assignment fails the run')
            ->toThrow(ProtocolError::class, '/sent no message for 0\.5 seconds/');
    }

    #[Test]
    #[Timeout(30.0)]
    public function aWorkerFatalMessageFailsTheRunWithItsDiagnostic(): void
    {
        $script = <<<'PHP'
            [, , $address, $workerId, $token] = $argv;
            $socket = stream_socket_client($address);

            $send = static function (array $message) use ($socket): void {
                $json = json_encode($message, JSON_THROW_ON_ERROR);
                fwrite($socket, pack('N', strlen($json)) . $json);
                fflush($socket);
            };

            $send([
                'v' => 1,
                't' => 'hello',
                'p' => [
                    'workerId' => $workerId,
                    'token' => $token,
                    'pid' => getmypid(),
                ],
            ]);
            $send([
                'v' => 1,
                't' => 'fatal',
                'p' => [
                    'detail' => [
                        'class' => 'RuntimeException',
                        'message' => 'fixture worker failed',
                        'file' => '/fixture/worker.php',
                        'line' => 42,
                        'stackFrames' => [],
                    ],
                ],
            ]);
            sleep(60);
            PHP;

        $orchestrator = new Orchestrator(
            workerCommand: [\PHP_BINARY, '-r', $script],
            workingDirectory: \sys_get_temp_dir(),
        );

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
        $script = <<<'PHP'
            [, , $address, $workerId, $token] = $argv;
            $socket = stream_socket_client($address);

            $send = static function (array $message) use ($socket): void {
                $json = json_encode($message, JSON_THROW_ON_ERROR);
                fwrite($socket, pack('N', strlen($json)) . $json);
                fflush($socket);
            };

            $read = static function (int $length) use ($socket): string {
                $bytes = '';

                while (strlen($bytes) < $length) {
                    $chunk = fread($socket, $length - strlen($bytes));

                    if ($chunk === false || $chunk === '') {
                        exit(1);
                    }

                    $bytes .= $chunk;
                }

                return $bytes;
            };

            $send(['v' => 1, 't' => 'hello', 'p' => ['workerId' => $workerId, 'token' => $token, 'pid' => getmypid()]]);
            $length = unpack('Nlength', $read(4))['length'];
            $read($length);
            $send([
                'v' => 1,
                't' => 'recycling',
                'p' => [
                    'reason' => 'test-count',
                    'remaining' => [],
                    'summary' => ['passed' => 1, 'failed' => 0, 'errored' => 0, 'skipped' => 0],
                    'coverage' => null,
                ],
            ]);
            sleep(60);
            PHP;

        $orchestrator = new Orchestrator(
            workerCommand: [\PHP_BINARY, '-r', $script],
            workingDirectory: \sys_get_temp_dir(),
        );

        Expect::that(fn(): ResultSummary => $orchestrator->run($this->plan(), new CollectingEventSink(), 1))->because('a recycling worker with a mismatched summary fails the run')
            ->toThrow(ProtocolError::class, '/reported a summary .* but its event stream totals/');
    }

    #[Test]
    #[Timeout(30.0)]
    public function resourceWaitStartsANewProgressWindowBeforeAssignment(): void
    {
        $root = \dirname(__DIR__, 4);
        $orchestrator = new Orchestrator(
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

    private function plan(): ExecutionPlan
    {
        $id = new TestId('Example\NeverExecutedTest', 'irrelevant');

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
}
