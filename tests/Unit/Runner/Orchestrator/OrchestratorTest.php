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
use Greenlight\Tests\Support\CollectingEventSink;

final class OrchestratorTest
{
    #[Test]
    #[Timeout(30.0)]
    public function aSpawnedWorkerThatNeverConnectsFailsTheRunInsteadOfHangingIt(): void
    {
        // A process that stays alive but never dials the orchestrator socket,
        // like a worker stuck in interpreter boot on an exhausted machine.
        $orchestrator = new Orchestrator(
            workerCommand: [\PHP_BINARY, '-r', 'fwrite(STDERR, "booting, honest"); sleep(60);'],
            workingDirectory: \sys_get_temp_dir(),
            connectDeadlineSeconds: 0.5,
        );

        Expect::that(fn(): ResultSummary => $orchestrator->run($this->plan(), new CollectingEventSink(), 1))
            ->toThrow(ProtocolError::class, '/never connected within 0\.5s/');
    }

    #[Test]
    #[Timeout(30.0)]
    public function aConnectedWorkerThatGoesSilentBeforeStartingItsAssignmentFailsTheRun(): void
    {
        // A worker that completes the hello handshake, receives its
        // assignment, then goes silent without ever reporting TestStarted.
        // No test is in flight, so per-test timeouts never fire, and the channel
        // stays open, so crash detection never fires either.
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

        Expect::that(fn(): ResultSummary => $orchestrator->run($this->plan(), new CollectingEventSink(), 1))
            ->toThrow(ProtocolError::class, '/sent nothing for 0\.5s/');
    }

    private function plan(): ExecutionPlan
    {
        $id = new TestId('Example\NeverExecutedTest', 'irrelevant');

        return new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($id->class, $id->method)),
        ]);
    }
}
