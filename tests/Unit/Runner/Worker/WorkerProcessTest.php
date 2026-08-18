<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Runner\Worker\WorkerProcess;
use Greenlight\Tests\Support\Subprocess;

final readonly class WorkerProcessTest
{
    public function __construct(private EnvironmentSandbox $environment) {}

    #[Test]
    public function connectionFailureNamesTheExactAddress(): void
    {
        $root = \dirname(__DIR__, 4);
        $address = 'unix://' . \sys_get_temp_dir()
            . '/greenlight-missing-worker-' . \bin2hex(\random_bytes(6)) . '.sock';

        $result = Subprocess::run($root, [
            \PHP_BINARY,
            '-r',
            <<<'PHP'
            require $argv[1];

            exit(new Greenlight\Runner\Worker\WorkerProcess()->run(
                $argv[2],
                'worker-under-test',
                'token',
            ));
            PHP,
            $root . '/vendor/autoload.php',
            $address,
        ]);

        Expect::that($result->exitCode)
            ->because('a worker connection failure MUST fail startup')
            ->toBe(1);
        Expect::that($result->stderr)
            ->toContain('The worker did not connect to ' . $address . ':');
    }

    #[Test]
    #[Timeout(5.0)]
    public function assignmentSetupFailuresAreReportedToTheOrchestrator(): void
    {
        $root = \dirname(__DIR__, 4);
        $missingConfig = \sys_get_temp_dir()
            . '/greenlight-missing-config-' . \bin2hex(\random_bytes(6)) . '.php';
        $server = Subprocess::start($root, [
            \PHP_BINARY,
            '-r',
            <<<'PHP'
            require $argv[1];

            $missingConfig = $argv[2];
            $socketPath = '/tmp/greenlight-worker-' . bin2hex(random_bytes(6)) . '.sock';
            register_shutdown_function(static fn() => @unlink($socketPath));
            $server = stream_socket_server('unix://' . $socketPath);

            if (!is_resource($server)) {
                exit(2);
            }

            fwrite(STDOUT, 'unix://' . $socketPath . "\n");
            fflush(STDOUT);

            $connection = stream_socket_accept($server, 2.0);

            if (!is_resource($connection)) {
                exit(3);
            }

            $channel = new Greenlight\Runner\Protocol\SocketChannel($connection);

            if (!$channel->receive(2.0) instanceof Greenlight\Runner\Protocol\Messages\Hello) {
                exit(4);
            }

            $channel->send(new Greenlight\Runner\Protocol\Messages\Bootstrap(
                1,
                $missingConfig,
                Greenlight\Harness\IntegrationResources::empty(),
            ));
            $fatal = $channel->receive(2.0);

            if (!$fatal instanceof Greenlight\Runner\Protocol\Messages\Fatal) {
                exit(5);
            }

            fwrite(STDOUT, $fatal->detail->class . "\n" . $fatal->detail->message . "\n");
            PHP,
            $root . '/vendor/autoload.php',
            $missingConfig,
        ]);

        try {
            $address = \trim($server->readStdoutUntil("\n", 2.0));

            if ($address === '') {
                Fail::because('Worker protocol server did not publish its address.');
            }

            $this->environment->set('GREENLIGHT_CHANNEL', '1');
            $workerExit = new WorkerProcess()->run($address, 'worker-under-test', 'token');
            $serverResult = $server->wait(2.0);

            Expect::that($workerExit)
                ->because('an assignment setup failure MUST stop the worker abnormally')
                ->toBe(1);
            Expect::that($serverResult->exitCode)
                ->because('the orchestrator fixture MUST receive the worker fatal message')
                ->toBe(0);
            Expect::that($serverResult->stdout)
                ->toContain("Greenlight\\Config\\ConfigFileError\n")
                ->toContain('Configuration file "' . $missingConfig . '" does not exist.');
        } finally {
            $server->terminate();
        }
    }

    #[Test]
    #[Timeout(5.0)]
    public function bootstrapRejectsATruncatedChannelEnvironmentValue(): void
    {
        [$workerExit, $serverExit] = $this->runScenario('bootstrap-channel-mismatch', '1worker');

        Expect::that($workerExit)
            ->because('a malformed channel environment value MUST fail worker bootstrap')
            ->toBe(1);
        Expect::that($serverExit)
            ->because('the protocol fixture MUST receive the channel mismatch diagnostic')
            ->toBe(0);
    }

    #[Test]
    #[Timeout(5.0)]
    public function itKeepsPollingAfterAnIdleReceiveTimesOut(): void
    {
        [$workerExit, $serverExit] = $this->runScenario('idle-then-drain');

        Expect::that($workerExit)->toBe(0);
        Expect::that($serverExit)->toBe(0);
    }

    #[Test]
    #[Timeout(5.0)]
    public function emptyAssignmentsCompleteBeforeTheWorkerDrains(): void
    {
        [$workerExit, $serverExit] = $this->runScenario('empty-assignment');

        Expect::that($workerExit)
            ->because('an empty assignment MUST complete and leave the worker available to drain')
            ->toBe(0);
        Expect::that($serverExit)
            ->because('the protocol fixture MUST receive the empty completion before it drains the worker')
            ->toBe(0);
    }

    #[Test]
    #[Timeout(5.0)]
    public function memoryThresholdRecyclesAWorkerAfterAnEmptyAssignment(): void
    {
        [$workerExit, $serverExit] = $this->runScenario('empty-assignment-memory-recycles');

        Expect::that($workerExit)
            ->because('a worker above its memory threshold MUST exit cleanly')
            ->toBe(0);
        Expect::that($serverExit)
            ->because('the protocol fixture MUST receive the memory recycle request')
            ->toBe(0);
    }

    #[Test]
    #[Timeout(5.0)]
    public function aPassingAssignmentReportsTestCountRecycling(): void
    {
        [$workerExit, $serverExit] = $this->runScenario('passing-assignment-recycles');

        Expect::that($workerExit)
            ->because('a worker that reaches its test-count budget MUST exit cleanly')
            ->toBe(0);
        Expect::that($serverExit)
            ->because('the protocol fixture MUST receive the completed result and recycle reason')
            ->toBe(0);
    }

    #[Test]
    #[Timeout(5.0)]
    public function completedAssignmentsAccumulateTowardTestCountRecycling(): void
    {
        [$workerExit, $serverExit] = $this->runScenario('completed-assignments-recycle');

        Expect::that($workerExit)
            ->because('a worker that reaches its cumulative test-count budget MUST exit cleanly')
            ->toBe(0);
        Expect::that($serverExit)
            ->because('the protocol fixture MUST receive both completions before recycling')
            ->toBe(0);
    }

    #[Test]
    #[Timeout(5.0)]
    #[DataSet('cleanControlChannelEndings')]
    public function controlChannelEndingsStopTheWorkerCleanly(string $scenario): void
    {
        [$workerExit, $serverExit] = $this->runScenario($scenario);

        Expect::that($workerExit)
            ->because('a control-channel ending MUST stop the worker cleanly')
            ->toBe(0);
        Expect::that($serverExit)
            ->toBe(0);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function cleanControlChannelEndings(): iterable
    {
        yield 'orchestrator disconnect' => ['eof'];
        yield 'unexpected message followed by drain' => ['unexpected-then-drain'];
    }

    /**
     * @return array{int, int}
     */
    private function runScenario(string $scenario, string $environmentChannel = '1'): array
    {
        $root = \dirname(__DIR__, 4);
        $server = Subprocess::start($root, [
            \PHP_BINARY,
            '-r',
            <<<'PHP'
            require $argv[1];

            $scenario = $argv[2];
            $socketPath = '/tmp/greenlight-worker-' . bin2hex(random_bytes(6)) . '.sock';
            register_shutdown_function(static fn() => @unlink($socketPath));
            $address = 'unix://' . $socketPath;
            $server = stream_socket_server($address);

            if (!is_resource($server)) {
                exit(2);
            }

            fwrite(STDOUT, $address . "\n");
            fflush(STDOUT);

            $connection = stream_socket_accept($server, 2.0);

            if (!is_resource($connection)) {
                exit(3);
            }

            $channel = new Greenlight\Runner\Protocol\SocketChannel($connection);

            if (!$channel->receive(2.0) instanceof Greenlight\Runner\Protocol\Messages\Hello) {
                exit(4);
            }

            if ($scenario === 'eof') {
                $channel->close();
                exit(0);
            }

            $channel->send(new Greenlight\Runner\Protocol\Messages\Bootstrap(
                1,
                null,
                Greenlight\Harness\IntegrationResources::empty(),
            ));

            $bootstrapResponse = $channel->receive(2.0);

            if ($scenario === 'bootstrap-channel-mismatch') {
                if (!$bootstrapResponse instanceof Greenlight\Runner\Protocol\Messages\Fatal
                    || $bootstrapResponse->detail->message !== 'Worker bootstrap channel does not match GREENLIGHT_CHANNEL.'
                ) {
                    exit(5);
                }

                exit(0);
            }

            if (!$bootstrapResponse instanceof Greenlight\Runner\Protocol\Messages\Ready) {
                exit(5);
            }

            if ($scenario === 'passing-assignment-recycles') {
                $class = Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::class;
                $id = new Greenlight\Core\Test\TestId($class, 'one');
                $channel->send(new Greenlight\Runner\Protocol\Messages\Assign(
                    new Greenlight\Discovery\ExecutionPlan([
                        new Greenlight\Discovery\PlanEntry(
                            $id,
                            new Greenlight\Core\Test\TestMetadata($class, 'one'),
                        ),
                    ]),
                    recycleAfterTests: 1,
                ));

                do {
                    $recycling = $channel->receive(2.0);
                } while ($recycling instanceof Greenlight\Runner\Protocol\Message
                    && !$recycling instanceof Greenlight\Runner\Protocol\Messages\Recycling);

                if (!$recycling instanceof Greenlight\Runner\Protocol\Messages\Recycling
                    || $recycling->reason !== Greenlight\Core\Event\RecycleReason::TestCount
                    || $recycling->summary->passed !== 1
                    || $recycling->remaining !== []
                ) {
                    exit(6);
                }

                exit(0);
            }

            if ($scenario === 'completed-assignments-recycle') {
                $class = Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::class;

                foreach (['one', 'two'] as $index => $method) {
                    $id = new Greenlight\Core\Test\TestId($class, $method);
                    $channel->send(new Greenlight\Runner\Protocol\Messages\Assign(
                        new Greenlight\Discovery\ExecutionPlan([
                            new Greenlight\Discovery\PlanEntry(
                                $id,
                                new Greenlight\Core\Test\TestMetadata($class, $method),
                            ),
                        ]),
                        recycleAfterTests: 2,
                    ));

                    do {
                        $done = $channel->receive(2.0);
                    } while ($done instanceof Greenlight\Runner\Protocol\Message
                        && !$done instanceof Greenlight\Runner\Protocol\Messages\Done);

                    $expectedRecycle = $index === 1
                        ? Greenlight\Core\Event\RecycleReason::TestCount
                        : null;

                    if (!$done instanceof Greenlight\Runner\Protocol\Messages\Done
                        || $done->summary->passed !== 1
                        || $done->wantsRecycle !== $expectedRecycle
                    ) {
                        exit(7);
                    }
                }

                exit(0);
            }

            if ($scenario === 'empty-assignment-memory-recycles') {
                $channel->send(new Greenlight\Runner\Protocol\Messages\Assign(
                    new Greenlight\Discovery\ExecutionPlan([]),
                    recycleAboveMemoryBytes: 1,
                ));
                $done = $channel->receive(2.0);

                if (!$done instanceof Greenlight\Runner\Protocol\Messages\Done
                    || $done->summary->total() !== 0
                    || $done->wantsRecycle !== Greenlight\Core\Event\RecycleReason::Memory
                ) {
                    exit(9);
                }

                exit(0);
            }

            if ($scenario === 'empty-assignment') {
                $channel->send(new Greenlight\Runner\Protocol\Messages\Assign(
                    new Greenlight\Discovery\ExecutionPlan([]),
                ));
                $done = $channel->receive(2.0);

                if (!$done instanceof Greenlight\Runner\Protocol\Messages\Done
                    || $done->summary->total() !== 0
                    || $done->wantsRecycle !== null
                ) {
                    exit(8);
                }
            } elseif ($scenario === 'unexpected-then-drain') {
                $channel->send(new Greenlight\Runner\Protocol\Messages\Hello('unexpected', 'token', 1));
            } elseif ($scenario === 'idle-then-drain') {
                usleep(100_000);
            } else {
                exit(9);
            }

            $channel->send(new Greenlight\Runner\Protocol\Messages\Drain());
            PHP,
            $root . '/vendor/autoload.php',
            $scenario,
        ]);

        try {
            $address = \trim($server->readStdoutUntil("\n", 2.0));

            if ($address === '') {
                Fail::because('Worker protocol server did not publish its address.');
            }

            $this->environment->set('GREENLIGHT_CHANNEL', $environmentChannel);
            $workerExit = new WorkerProcess(0.01)->run($address, 'worker-under-test', 'token');
            $serverResult = $server->wait(2.0);

            return [$workerExit, $serverResult->exitCode];
        } finally {
            $server->terminate();
        }
    }
}
