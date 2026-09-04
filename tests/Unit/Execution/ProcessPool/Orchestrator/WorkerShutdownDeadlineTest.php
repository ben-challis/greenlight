<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\NativeOrchestrator;
use Greenlight\Tests\Support\PhpSubprocess;
use Greenlight\Tests\Support\PlanEntryFixture;

final readonly class WorkerShutdownDeadlineTest
{
    #[Test]
    #[Timeout(10.0)]
    public function aWorkerThatStallsAfterDrainFailsTheRun(): void
    {
        $root = \dirname(__DIR__, 5);
        $script = \sprintf(
            <<<'PHP'
                require %s;
                [, , $address, $workerId, $token] = $argv;
                $channel = new Greenlight\Execution\ProcessPool\Protocol\SocketChannel(stream_socket_client($address));
                $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\Hello($workerId, $token, getmypid()));
                $channel->receive(5.0);
                $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\Ready());
                $assignment = $channel->receive(5.0);
                $id = $assignment->slice->entries[0]->id;
                $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope(
                    new Greenlight\Event\TestStarted($id, microtime(true)),
                ));
                $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope(
                    new Greenlight\Event\TestFinished(
                        new Greenlight\Result\TestResult($id, Greenlight\Result\Outcome::Passed, 0.0, 0),
                        microtime(true),
                    ),
                ));
                $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\Done(
                    new Greenlight\Result\ResultSummary(passed: 1),
                    0,
                ));
                $channel->receive(5.0);
                sleep(2);
                PHP,
            \var_export($root . '/vendor/autoload.php', true),
        );
        $orchestrator = NativeOrchestrator::create(
            workerCommand: PhpSubprocess::command(['-r', $script]),
            workingDirectory: $root,
            progressDeadlineSeconds: 0.5,
        );
        $sink = new CollectingEventSink();
        $plan = new ExecutionPlan([PlanEntryFixture::create('Example\\ShutdownTest', 'passes')]);

        Expect::that(static fn() => $orchestrator->run($plan, $sink, 1))
            ->toThrow(ProtocolError::class, '/sent no message for 0\.5 seconds/');
        Expect::that($sink->results())->toHaveCount(1);
    }
}
