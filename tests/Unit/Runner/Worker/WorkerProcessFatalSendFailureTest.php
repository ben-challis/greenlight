<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Worker\WorkerProcess;
use Greenlight\Tests\Support\Subprocess;

final class WorkerProcessFatalSendFailureTest
{
    #[Test]
    #[Timeout(5.0)]
    public function aClosedChannelCannotHideTheAssignmentFailure(): void
    {
        $root = \dirname(__DIR__, 4);
        $config = \sys_get_temp_dir()
            . '/greenlight-failing-config-' . \bin2hex(\random_bytes(6)) . '.php';
        $ready = \sys_get_temp_dir()
            . '/greenlight-failing-config-ready-' . \bin2hex(\random_bytes(6));
        $release = \sys_get_temp_dir()
            . '/greenlight-failing-config-release-' . \bin2hex(\random_bytes(6));
        $server = Subprocess::start($root, [
            \PHP_BINARY,
            '-r',
            <<<'PHP'
            require $argv[1];

            $config = $argv[2];
            $ready = $argv[3];
            $release = $argv[4];
            $socketPath = '/tmp/greenlight-worker-' . bin2hex(random_bytes(6)) . '.sock';
            register_shutdown_function(static function () use ($socketPath, $config, $ready, $release): void {
                @unlink($socketPath);
                @unlink($config);
                @unlink($ready);
                @unlink($release);
            });

            $written = file_put_contents(
                $config,
                "<?php\n"
                . "touch(" . var_export($ready, true) . ");\n"
                . "while (true) {\n"
                . "    clearstatcache(true, " . var_export($release, true) . ");\n"
                . "    if (is_file(" . var_export($release, true) . ")) {\n"
                . "        break;\n"
                . "    }\n"
                . "    usleep(1_000);\n"
                . "}\n"
                . "unlink(" . var_export($ready, true) . ");\n"
                . "unlink(" . var_export($release, true) . ");\n"
                . "throw new RuntimeException('assignment failed');\n",
            );

            if ($written === false) {
                exit(2);
            }

            $server = stream_socket_server('unix://' . $socketPath);

            if (!is_resource($server)) {
                exit(3);
            }

            fwrite(STDOUT, 'unix://' . $socketPath . "\n");
            fflush(STDOUT);

            $connection = stream_socket_accept($server, 2.0);

            if (!is_resource($connection)) {
                exit(4);
            }

            $channel = new Greenlight\Runner\Protocol\SocketChannel($connection);

            if (!$channel->receive(2.0) instanceof Greenlight\Runner\Protocol\Messages\Hello) {
                exit(5);
            }

            $channel->send(new Greenlight\Runner\Protocol\Messages\Assign(
                new Greenlight\Discovery\ExecutionPlan([]),
                configFile: $config,
            ));
            $deadline = microtime(true) + 2.0;

            while (microtime(true) < $deadline) {
                clearstatcache(true, $ready);

                if (is_file($ready)) {
                    break;
                }

                usleep(1_000);
            }

            clearstatcache(true, $ready);

            if (!is_file($ready)) {
                exit(6);
            }

            $socket = socket_import_stream($connection);

            if (!$socket instanceof Socket
                || !socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0])
            ) {
                exit(7);
            }

            $channel->close();

            if (!touch($release)) {
                exit(8);
            }

            $deadline = microtime(true) + 2.0;

            while (microtime(true) < $deadline) {
                clearstatcache(true, $release);

                if (!is_file($release)) {
                    break;
                }

                usleep(1_000);
            }

            clearstatcache(true, $release);

            if (is_file($release)) {
                exit(9);
            }
            PHP,
            $root . '/vendor/autoload.php',
            $config,
            $ready,
            $release,
        ]);

        try {
            $address = \trim($server->readStdoutUntil("\n", 2.0));

            if ($address === '') {
                Fail::because('Worker protocol server did not publish its address.');
            }

            $workerExit = new WorkerProcess()->run($address, 'worker-under-test', 'token');
            $serverResult = $server->wait(3.0);

            Expect::that($workerExit)
                ->because('a failed final report MUST not escape the worker process')
                ->toBe(1);
            Expect::that($serverResult->exitCode)
                ->because('the protocol fixture MUST reset the channel before it releases the assignment failure')
                ->toBe(0);
        } finally {
            $server->terminate();
        }
    }
}
