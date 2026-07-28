<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ConfigFileError;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Protocol\Message;
use Greenlight\Runner\Protocol\Messages\Assign;
use Greenlight\Runner\Protocol\Messages\Done;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\Messages\Fatal;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Runner\Protocol\Messages\Recycling;
use Greenlight\Runner\Worker\WorkerSession;
use Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest;
use Greenlight\Tests\Fixture\Runner\Worker\FakeWorkerChannel;

final class WorkerSessionTest
{
    #[Test]
    public function assignmentSetupFailuresAreReportedToTheOrchestrator(): void
    {
        $missingConfig = \sys_get_temp_dir()
            . '/greenlight-missing-config-' . \bin2hex(\random_bytes(6)) . '.php';
        $channel = new FakeWorkerChannel([
            new Assign(new ExecutionPlan([]), configFile: $missingConfig),
        ]);

        $workerExit = new WorkerSession(30.0)->run($channel, 'worker-under-test', 'token');
        $fatal = \array_find(
            $channel->sent(),
            static fn(Message $message): bool => $message instanceof Fatal,
        );

        if (!$fatal instanceof Fatal) {
            Fail::because('The worker did not report the assignment setup failure.');
        }

        Expect::that($workerExit)
            ->because('an assignment setup failure MUST stop the worker abnormally')
            ->toBe(1)
            ->and($fatal->detail->class)
            ->toBe(ConfigFileError::class)
            ->and($fatal->detail->message)
            ->toContain('Configuration file "' . $missingConfig . '" does not exist.');
    }

    #[Test]
    public function itKeepsPollingAfterAnIdleReceiveTimesOut(): void
    {
        $channel = new FakeWorkerChannel([null, new Drain()]);

        $workerExit = new WorkerSession(30.0)->run($channel, 'worker-under-test', 'token');

        Expect::that($workerExit)->toBe(0)
            ->and($channel->receiveTimeouts())->toBe([30.0, 30.0])
            ->and($channel->closed())->toBeTrue();
    }

    #[Test]
    public function emptyAssignmentsCompleteBeforeTheWorkerDrains(): void
    {
        $channel = new FakeWorkerChannel([
            new Assign(new ExecutionPlan([])),
            new Drain(),
        ]);

        $workerExit = new WorkerSession(30.0)->run($channel, 'worker-under-test', 'token');
        $done = $this->sentMessage($channel, Done::class);

        Expect::that($workerExit)
            ->because('an empty assignment MUST complete and leave the worker available to drain')
            ->toBe(0)
            ->and($done->summary->total())
            ->toBe(0)
            ->and($done->wantsRecycle)
            ->toBeNull();
    }

    #[Test]
    public function memoryThresholdRecyclesAWorkerAfterAnEmptyAssignment(): void
    {
        $channel = new FakeWorkerChannel([
            new Assign(new ExecutionPlan([]), recycleAboveMemoryBytes: 1),
        ]);

        $workerExit = new WorkerSession(30.0)->run($channel, 'worker-under-test', 'token');
        $done = $this->sentMessage($channel, Done::class);

        Expect::that($workerExit)
            ->because('a worker above its memory threshold MUST exit cleanly')
            ->toBe(0)
            ->and($done->summary->total())
            ->toBe(0)
            ->and($done->wantsRecycle)
            ->toBe(RecycleReason::Memory);
    }

    #[Test]
    public function aPassingAssignmentReportsTestCountRecycling(): void
    {
        $channel = new FakeWorkerChannel([
            new Assign(
                $this->plan('one'),
                recycleAfterTests: 1,
            ),
        ]);

        $workerExit = new WorkerSession(30.0)->run($channel, 'worker-under-test', 'token');
        $recycling = $this->sentMessage($channel, Recycling::class);

        Expect::that($workerExit)
            ->because('a worker that reaches its test-count budget MUST exit cleanly')
            ->toBe(0)
            ->and($recycling->reason)
            ->toBe(RecycleReason::TestCount)
            ->and($recycling->summary->passed)
            ->toBe(1)
            ->and($recycling->remaining)
            ->toBe([]);
    }

    #[Test]
    public function completedAssignmentsAccumulateTowardTestCountRecycling(): void
    {
        $channel = new FakeWorkerChannel([
            new Assign($this->plan('one'), recycleAfterTests: 2),
            new Assign($this->plan('two'), recycleAfterTests: 2),
        ]);

        $workerExit = new WorkerSession(30.0)->run($channel, 'worker-under-test', 'token');
        $done = \array_values(\array_filter(
            $channel->sent(),
            static fn(Message $message): bool => $message instanceof Done,
        ));

        Expect::that($workerExit)
            ->because('a worker that reaches its cumulative test-count budget MUST exit cleanly')
            ->toBe(0)
            ->and($done)
            ->toHaveCount(2);

        if (!isset($done[0], $done[1])) {
            Fail::because('The worker did not report both completed assignments.');
        }

        Expect::that($done[0]->summary->passed)->toBe(1)
            ->and($done[0]->wantsRecycle)->toBeNull()
            ->and($done[1]->summary->passed)->toBe(1)
            ->and($done[1]->wantsRecycle)->toBe(RecycleReason::TestCount);
    }

    #[Test]
    #[DataSet('cleanControlChannelEndings')]
    public function controlChannelEndingsStopTheWorkerCleanly(FakeWorkerChannel $channel): void
    {
        $workerExit = new WorkerSession(30.0)->run($channel, 'worker-under-test', 'token');

        Expect::that($workerExit)
            ->because('a control-channel ending MUST stop the worker cleanly')
            ->toBe(0)
            ->and($channel->closed())
            ->toBeTrue();
    }

    /**
     * @return iterable<string, array{FakeWorkerChannel}>
     */
    public static function cleanControlChannelEndings(): iterable
    {
        yield 'orchestrator disconnect' => [new FakeWorkerChannel([null], eofAfterReceives: 1)];
        yield 'unexpected message followed by drain' => [
            new FakeWorkerChannel([
                new Hello('unexpected', 'token', 1),
                new Drain(),
            ]),
        ];
    }

    /**
     * @param non-empty-string $method
     */
    private function plan(string $method): ExecutionPlan
    {
        $id = new TestId(AlphaTest::class, $method);

        return new ExecutionPlan([
            new PlanEntry(
                $id,
                new TestMetadata(AlphaTest::class, $method),
            ),
        ]);
    }

    /**
     * @template T of Message
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    private function sentMessage(FakeWorkerChannel $channel, string $type): Message
    {
        $message = \array_find(
            $channel->sent(),
            static fn(Message $message): bool => $message instanceof $type,
        );

        if (!$message instanceof $type) {
            Fail::because('The worker did not send ' . $type . '.');
        }

        return $message;
    }
}
