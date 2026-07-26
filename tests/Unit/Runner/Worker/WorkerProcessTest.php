<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Worker\WorkerProcess;
use Greenlight\Tests\Support\Subprocess;

final class WorkerProcessTest
{
    #[Test]
    #[Timeout(5.0)]
    public function itKeepsPollingAfterAnIdleReceiveTimesOut(): void
    {
        $root = \dirname(__DIR__, 4);
        $server = Subprocess::start($root, [
            \PHP_BINARY,
            '-r',
            <<<'PHP'
            require $argv[1];

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

            usleep(100_000);
            $channel->send(new Greenlight\Runner\Protocol\Messages\Drain());
            PHP,
            $root . '/vendor/autoload.php',
        ]);

        try {
            $address = \trim($server->readStdoutUntil("\n", 2.0));

            if ($address === '') {
                Fail::because('Worker protocol server did not publish its address.');
            }

            $exitCode = new WorkerProcess(0.01)->run($address, 'worker-under-test', 'token');
            $serverResult = $server->wait(2.0);

            Expect::that($exitCode)->toBe(0)
                ->and($serverResult->exitCode)->toBe(0);
        } finally {
            $server->terminate();
        }
    }
}
