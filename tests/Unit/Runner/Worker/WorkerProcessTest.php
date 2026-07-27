<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Worker\WorkerProcess;
use Greenlight\Tests\Support\Subprocess;

final class WorkerProcessTest
{
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
            ->toBe(1)
            ->and($result->stderr)
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

            $channel->send(new Greenlight\Runner\Protocol\Messages\Assign(
                new Greenlight\Discovery\ExecutionPlan([]),
                configFile: $missingConfig,
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

            $workerExit = new WorkerProcess()->run($address, 'worker-under-test', 'token');
            $serverResult = $server->wait(2.0);

            Expect::that($workerExit)
                ->because('an assignment setup failure MUST stop the worker abnormally')
                ->toBe(1)
                ->and($serverResult->exitCode)
                ->because('the orchestrator fixture MUST receive the worker fatal message')
                ->toBe(0)
                ->and($serverResult->stdout)
                ->toContain("Greenlight\\Config\\ConfigFileError\n")
                ->toContain('Configuration file "' . $missingConfig . '" does not exist.');
        } finally {
            $server->terminate();
        }
    }

    #[Test]
    #[Timeout(5.0)]
    public function itKeepsPollingAfterAnIdleReceiveTimesOut(): void
    {
        [$workerExit, $serverExit] = $this->runScenario('idle-then-drain');

        Expect::that($workerExit)->toBe(0)
            ->and($serverExit)->toBe(0);
    }

    #[Test]
    #[Timeout(5.0)]
    #[DataSet('cleanControlChannelEndings')]
    public function controlChannelEndingsStopTheWorkerCleanly(string $scenario): void
    {
        [$workerExit, $serverExit] = $this->runScenario($scenario);

        Expect::that($workerExit)
            ->because('a control-channel ending MUST stop the worker cleanly')
            ->toBe(0)
            ->and($serverExit)
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
    private function runScenario(string $scenario): array
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

            if ($scenario === 'unexpected-then-drain') {
                $channel->send(new Greenlight\Runner\Protocol\Messages\Hello('unexpected', 'token', 1));
            } elseif ($scenario === 'idle-then-drain') {
                usleep(100_000);
            } else {
                exit(5);
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

            $workerExit = new WorkerProcess(0.01)->run($address, 'worker-under-test', 'token');
            $serverResult = $server->wait(2.0);

            return [$workerExit, $serverResult->exitCode];
        } finally {
            $server->terminate();
        }
    }
}
