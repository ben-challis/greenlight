<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Runner\Worker\WorkerProcess;
use Greenlight\Tests\Support\Subprocess;

final readonly class WorkerProcessFatalSendFailureTest
{
    public function __construct(private EnvironmentSandbox $environment) {}

    #[Test]
    #[Timeout(5.0)]
    public function aClosedChannelCannotHideTheAssignmentFailure(): void
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
            stream_socket_shutdown($connection, STREAM_SHUT_RDWR);
            $channel->close();
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
                ->because('a failed final report MUST not escape the worker process')
                ->toBe(1)
                ->and($serverResult->exitCode)
                ->because('the protocol fixture MUST close the channel after the assignment')
                ->toBe(0);
        } finally {
            $server->terminate();
        }
    }
}
