<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Worker;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Condition\FunctionAvailable;
use Greenlight\Execution\ProcessPool\Worker\WorkerProcess;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\PhpSubprocess;

final readonly class WorkerProcessTest
{
    public function __construct(
        private EnvironmentVariables $environment,
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function connectionFailureNamesTheExactAddress(): void
    {
        $root = \dirname(__DIR__, 5);
        $address = 'unix://' . $this->tempDirectory->path() . '/missing-worker.sock';

        $result = PhpSubprocess::run($root, [
            '-r',
            <<<'PHP'
            require $argv[1];

            exit(new Greenlight\Execution\ProcessPool\Worker\WorkerProcess()->run(
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
    #[SkipUnless(FunctionAvailable::class, 'pcntl_signal_get_handler')]
    public function runRestoresTheCallingProcessInterruptHandler(): void
    {
        $before = \pcntl_signal_get_handler(\SIGINT);
        $callerHandler = static function (): void {};

        try {
            \pcntl_signal(\SIGINT, $callerHandler);

            $address = 'unix://' . $this->tempDirectory->path() . '/missing-worker.sock';

            Expect::that(new WorkerProcess()->run($address, 'worker-under-test', 'token'))
                ->because('a connection failure MUST return control to the calling process')
                ->toBe(1);
            Expect::that(\pcntl_signal_get_handler(\SIGINT))
                ->because('an in-process worker run MUST restore the caller SIGINT handler')
                ->toBe($callerHandler);
        } finally {
            \pcntl_signal(\SIGINT, $before);
        }
    }

    #[Test]
    #[Timeout(5.0)]
    public function assignmentSetupFailuresAreReportedToTheOrchestrator(): void
    {
        $root = \dirname(__DIR__, 5);
        $missingConfig = $this->tempDirectory->path() . '/missing-config.php';
        $server = PhpSubprocess::start($root, [
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

            $channel = new Greenlight\Execution\ProcessPool\Protocol\SocketChannel($connection);

            if (!$channel->receive(2.0) instanceof Greenlight\Execution\ProcessPool\Protocol\Messages\Hello) {
                exit(4);
            }

            $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\Bootstrap(
                1,
                $missingConfig,
                Greenlight\IntegrationFixture\IntegrationResources::empty(),
            ));
            $fatal = $channel->receive(2.0);

            if (!$fatal instanceof Greenlight\Execution\ProcessPool\Protocol\Messages\Fatal) {
                exit(5);
            }

            fwrite(STDOUT, $fatal->detail->class . "\n" . $fatal->detail->message . "\n");
            PHP,
            $root . '/vendor/autoload.php',
            $missingConfig,
        ]);
        $this->cleanup->defer($server->terminate(...));

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
        yield 'orchestrator disconnect before bootstrap' => ['eof'];
        yield 'drain before bootstrap' => ['drain-before-bootstrap'];
        yield 'orchestrator disconnect after bootstrap' => ['bootstrap-then-eof'];
        yield 'unexpected message before bootstrap' => ['unexpected-before-bootstrap'];
        yield 'unexpected message after bootstrap' => ['unexpected-then-drain'];
    }

    #[Test]
    #[Timeout(5.0)]
    #[DataSet('workerProtocolViolations')]
    public function protocolViolationsStopTheWorkerAbnormally(string $scenario): void
    {
        [$workerExit, $serverExit] = $this->runScenario($scenario);

        Expect::that($workerExit)
            ->because('a worker protocol violation MUST stop the worker abnormally')
            ->toBe(1);
        Expect::that($serverExit)
            ->because('the protocol fixture MUST receive the worker fatal message')
            ->toBe(0);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function workerProtocolViolations(): iterable
    {
        yield 'assignment before bootstrap' => ['assignment-before-bootstrap'];
        yield 'duplicate bootstrap' => ['duplicate-bootstrap'];
    }

    /**
     * @return array{int, int}
     */
    private function runScenario(string $scenario, string $environmentChannel = '1'): array
    {
        $root = \dirname(__DIR__, 5);
        $server = PhpSubprocess::start($root, [
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

            $channel = new Greenlight\Execution\ProcessPool\Protocol\SocketChannel($connection);

            if (!$channel->receive(2.0) instanceof Greenlight\Execution\ProcessPool\Protocol\Messages\Hello) {
                exit(4);
            }

            if ($scenario === 'eof') {
                $channel->close();
                exit(0);
            }

            if ($scenario === 'drain-before-bootstrap') {
                $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\Drain());
                exit(0);
            }

            if ($scenario === 'assignment-before-bootstrap') {
                $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\Assign(
                    new Greenlight\Discovery\Plan\ExecutionPlan([]),
                ));
                $fatal = $channel->receive(2.0);

                if (!$fatal instanceof Greenlight\Execution\ProcessPool\Protocol\Messages\Fatal
                    || $fatal->detail->message !== 'Worker received an assignment before bootstrap completed.'
                ) {
                    exit(5);
                }

                exit(0);
            }

            if ($scenario === 'unexpected-before-bootstrap') {
                $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\Hello('unexpected', 'token', 1));
            }

            $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\Bootstrap(
                1,
                null,
                Greenlight\IntegrationFixture\IntegrationResources::empty(),
            ));

            $bootstrapResponse = $channel->receive(2.0);

            if ($scenario === 'bootstrap-channel-mismatch') {
                if (!$bootstrapResponse instanceof Greenlight\Execution\ProcessPool\Protocol\Messages\Fatal
                    || $bootstrapResponse->detail->message !== 'Worker bootstrap channel does not match GREENLIGHT_CHANNEL.'
                ) {
                    exit(5);
                }

                exit(0);
            }

            if (!$bootstrapResponse instanceof Greenlight\Execution\ProcessPool\Protocol\Messages\Ready) {
                exit(5);
            }

            if ($scenario === 'bootstrap-then-eof') {
                $channel->close();
                exit(0);
            }

            if ($scenario === 'duplicate-bootstrap') {
                $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\Bootstrap(
                    1,
                    null,
                    Greenlight\IntegrationFixture\IntegrationResources::empty(),
                ));
                $fatal = $channel->receive(2.0);

                if (!$fatal instanceof Greenlight\Execution\ProcessPool\Protocol\Messages\Fatal
                    || $fatal->detail->message !== 'Worker received bootstrap more than once.'
                ) {
                    exit(6);
                }

                exit(0);
            }

            if ($scenario === 'empty-assignment') {
                $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\Assign(
                    new Greenlight\Discovery\Plan\ExecutionPlan([]),
                ));
                $done = $channel->receive(2.0);

                if (!$done instanceof Greenlight\Execution\ProcessPool\Protocol\Messages\Done
                    || $done->summary->total() !== 0
                ) {
                    exit(8);
                }
            } elseif ($scenario === 'unexpected-then-drain') {
                $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\Hello('unexpected', 'token', 1));
            } elseif ($scenario === 'idle-then-drain') {
                usleep(100_000);
            } elseif ($scenario !== 'unexpected-before-bootstrap') {
                exit(9);
            }

            $channel->send(new Greenlight\Execution\ProcessPool\Protocol\Messages\Drain());
            PHP,
            $root . '/vendor/autoload.php',
            $scenario,
        ]);
        $this->cleanup->defer($server->terminate(...));

        $address = \trim($server->readStdoutUntil("\n", 2.0));

        if ($address === '') {
            Fail::because('Worker protocol server did not publish its address.');
        }

        $this->environment->set('GREENLIGHT_CHANNEL', $environmentChannel);
        $workerExit = new WorkerProcess(0.01)->run($address, 'worker-under-test', 'token');
        $serverResult = $server->wait(2.0);

        return [$workerExit, $serverResult->exitCode];
    }
}
