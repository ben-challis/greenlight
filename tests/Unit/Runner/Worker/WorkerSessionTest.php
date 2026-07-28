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
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Harness\IntegrationResources;
use Greenlight\Runner\Protocol\Message;
use Greenlight\Runner\Protocol\Messages\Assign;
use Greenlight\Runner\Protocol\Messages\Bootstrap;
use Greenlight\Runner\Protocol\Messages\Done;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\Messages\Fatal;
use Greenlight\Runner\Protocol\Messages\Hello;
use Greenlight\Runner\Protocol\Messages\Recycling;
use Greenlight\Runner\Worker\WorkerSession;
use Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest;
use Greenlight\Tests\Fixture\Runner\Worker\FakeWorkerChannel;

final readonly class WorkerSessionTest
{
    public function __construct(private EnvironmentSandbox $environment) {}

    #[Test]
    public function bootstrapFailuresAreReportedToTheOrchestrator(): void
    {
        $missingConfig = \sys_get_temp_dir()
            . '/greenlight-missing-config-' . \bin2hex(\random_bytes(6)) . '.php';
        $channel = new FakeWorkerChannel([$this->bootstrap(configFile: $missingConfig)]);

        $workerExit = $this->run($channel);
        $fatal = $this->sentMessage($channel, Fatal::class);

        Expect::that($workerExit)
            ->because('a bootstrap failure MUST stop the worker abnormally')
            ->toBe(1);
        Expect::that($fatal->detail->class)
            ->toBe(ConfigFileError::class);
        Expect::that($fatal->detail->message)
            ->toContain('Configuration file "' . $missingConfig . '" does not exist.');
    }

    #[Test]
    public function itKeepsPollingAfterAnIdleReceiveTimesOut(): void
    {
        $channel = new FakeWorkerChannel([null, new Drain()]);

        $workerExit = $this->run($channel);

        Expect::that($workerExit)->toBe(0);
        Expect::that($channel->receiveTimeouts())->toBe([30.0, 30.0]);
        Expect::that($channel->closed())->toBeTrue();
    }

    #[Test]
    public function emptyAssignmentsCompleteBeforeTheWorkerDrains(): void
    {
        $channel = new FakeWorkerChannel([
            $this->bootstrap(),
            new Assign(new ExecutionPlan([])),
            new Drain(),
        ]);

        $workerExit = $this->run($channel);
        $done = $this->sentMessage($channel, Done::class);

        Expect::that($workerExit)
            ->because('an empty assignment MUST complete and leave the worker available to drain')
            ->toBe(0);
        Expect::that($done->summary->total())->toBe(0);
        Expect::that($done->wantsRecycle)->toBeNull();
    }

    #[Test]
    public function memoryThresholdRecyclesAWorkerAfterAnEmptyAssignment(): void
    {
        $channel = new FakeWorkerChannel([
            $this->bootstrap(),
            new Assign(new ExecutionPlan([]), recycleAboveMemoryBytes: 1),
        ]);

        $workerExit = $this->run($channel);
        $done = $this->sentMessage($channel, Done::class);

        Expect::that($workerExit)
            ->because('a worker above its memory threshold MUST exit cleanly')
            ->toBe(0);
        Expect::that($done->summary->total())->toBe(0);
        Expect::that($done->wantsRecycle)->toBe(RecycleReason::Memory);
    }

    #[Test]
    public function aPassingAssignmentReportsTestCountRecycling(): void
    {
        $channel = new FakeWorkerChannel([
            $this->bootstrap(),
            new Assign($this->plan('one'), recycleAfterTests: 1),
        ]);

        $workerExit = $this->run($channel);
        $recycling = $this->sentMessage($channel, Recycling::class);

        Expect::that($workerExit)
            ->because('a worker that reaches its test-count budget MUST exit cleanly')
            ->toBe(0);
        Expect::that($recycling->reason)->toBe(RecycleReason::TestCount);
        Expect::that($recycling->summary->passed)->toBe(1);
        Expect::that($recycling->remaining)->toBe([]);
    }

    #[Test]
    public function completedAssignmentsAccumulateTowardTestCountRecycling(): void
    {
        $channel = new FakeWorkerChannel([
            $this->bootstrap(),
            new Assign($this->plan('one'), recycleAfterTests: 2),
            new Assign($this->plan('two'), recycleAfterTests: 2),
        ]);

        $workerExit = $this->run($channel);
        $done = \array_values(\array_filter(
            $channel->sent(),
            static fn(Message $message): bool => $message instanceof Done,
        ));

        Expect::that($workerExit)
            ->because('a worker that reaches its cumulative test-count budget MUST exit cleanly')
            ->toBe(0);
        Expect::that($done)->toHaveCount(2);

        if (!isset($done[0], $done[1])) {
            Fail::because('The worker did not report both completed assignments.');
        }

        Expect::that($done[0]->summary->passed)->toBe(1);
        Expect::that($done[0]->wantsRecycle)->toBeNull();
        Expect::that($done[1]->summary->passed)->toBe(1);
        Expect::that($done[1]->wantsRecycle)->toBe(RecycleReason::TestCount);
    }

    #[Test]
    #[DataSet('cleanControlChannelEndings')]
    public function controlChannelEndingsStopTheWorkerCleanly(FakeWorkerChannel $channel): void
    {
        $workerExit = $this->run($channel);

        Expect::that($workerExit)
            ->because('a control-channel ending MUST stop the worker cleanly')
            ->toBe(0);
        Expect::that($channel->closed())->toBeTrue();
    }

    /**
     * @return iterable<string, array{FakeWorkerChannel}>
     */
    public static function cleanControlChannelEndings(): iterable
    {
        yield 'orchestrator disconnect before bootstrap' => [
            new FakeWorkerChannel([null], eofAfterReceives: 1),
        ];
        yield 'drain before bootstrap' => [new FakeWorkerChannel([new Drain()])];
        yield 'orchestrator disconnect after bootstrap' => [
            new FakeWorkerChannel([
                self::staticBootstrap(),
                null,
            ], eofAfterReceives: 2),
        ];
        yield 'unexpected message before bootstrap' => [
            new FakeWorkerChannel([
                new Hello('unexpected', 'token', 1),
                self::staticBootstrap(),
                new Drain(),
            ]),
        ];
        yield 'unexpected message after bootstrap' => [
            new FakeWorkerChannel([
                self::staticBootstrap(),
                new Hello('unexpected', 'token', 1),
                new Drain(),
            ]),
        ];
    }

    #[Test]
    #[DataSet('protocolViolations')]
    public function protocolViolationsStopTheWorkerAbnormally(
        FakeWorkerChannel $channel,
        string $message,
    ): void {
        $workerExit = $this->run($channel);
        $fatal = $this->sentMessage($channel, Fatal::class);

        Expect::that($workerExit)
            ->because('a worker protocol violation MUST stop the worker abnormally')
            ->toBe(1);
        Expect::that($fatal->detail->message)->toBe($message);
    }

    /**
     * @return iterable<string, array{FakeWorkerChannel, non-empty-string}>
     */
    public static function protocolViolations(): iterable
    {
        yield 'assignment before bootstrap' => [
            new FakeWorkerChannel([new Assign(new ExecutionPlan([]))]),
            'Worker received an assignment before bootstrap completed.',
        ];
        yield 'duplicate bootstrap' => [
            new FakeWorkerChannel([self::staticBootstrap(), self::staticBootstrap()]),
            'Worker received bootstrap more than once.',
        ];
    }

    #[Test]
    public function bootstrapRejectsATruncatedChannelEnvironmentValue(): void
    {
        $channel = new FakeWorkerChannel([$this->bootstrap()]);

        $workerExit = $this->run($channel, '1worker');
        $fatal = $this->sentMessage($channel, Fatal::class);

        Expect::that($workerExit)
            ->because('a malformed channel environment value MUST fail worker bootstrap')
            ->toBe(1);
        Expect::that($fatal->detail->message)
            ->toBe('Worker bootstrap channel does not match GREENLIGHT_CHANNEL.');
    }

    private function run(FakeWorkerChannel $channel, string $environmentChannel = '1'): int
    {
        $this->environment->set('GREENLIGHT_CHANNEL', $environmentChannel);

        return new WorkerSession(30.0)->run($channel, 'worker-under-test', 'token');
    }

    /**
     * @param non-empty-string|null $configFile
     */
    private function bootstrap(?string $configFile = null): Bootstrap
    {
        return self::staticBootstrap($configFile);
    }

    /**
     * @param non-empty-string|null $configFile
     */
    private static function staticBootstrap(?string $configFile = null): Bootstrap
    {
        return new Bootstrap(1, $configFile, IntegrationResources::empty());
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
