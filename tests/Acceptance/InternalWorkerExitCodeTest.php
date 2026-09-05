<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Drain;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Hello;
use Greenlight\Execution\ProcessPool\Protocol\SocketChannel;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class InternalWorkerExitCodeTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    #[DataSet('shutdownModes')]
    public function shutdownBeforeBootstrapReturnsSuccess(bool $drain): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0');

        if ($server === false) {
            Fail::because('Could not start the worker protocol server.');
        }

        try {
            $address = \stream_socket_get_name($server, false);

            if ($address === false) {
                Fail::because('Could not read the worker protocol server address.');
            }

            $process = GreenlightCli::start(\dirname(__DIR__, 2), ['__worker', 'tcp://' . $address, 'worker-exit', 'token']);
            $this->cleanup->defer($process->terminate(...));
            $connection = \stream_socket_accept($server, 5.0);

            if ($connection === false) {
                Fail::because('The worker did not connect to the protocol server.');
            }

            $channel = new SocketChannel($connection);

            try {
                Expect::that($channel->receive(5.0))->toBeInstanceOf(Hello::class);

                if ($drain) {
                    $channel->send(new Drain());
                }
            } finally {
                $channel->close();
            }

            $result = $process->wait(5.0);

            Expect::that($result->exitCode)->toBe(0);
            Expect::that($result->stderr)->toBe('');
        } finally {
            \fclose($server);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function shutdownModes(): iterable
    {
        yield 'drain' => [true];
        yield 'closed connection' => [false];
    }
}
