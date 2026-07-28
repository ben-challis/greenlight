<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Orchestrator\ServerSocket;

final class ServerSocketTest
{
    #[Test]
    public function tcpFallbackListensWhenTheUnixSocketCannotOpen(): void
    {
        $temporaryDirectory = \is_dir('/tmp') ? '/tmp' : \sys_get_temp_dir();
        $blocked = \tempnam($temporaryDirectory, 'greenlight-socket-');
        $socket = null;

        try {
            if (!\is_string($blocked)) {
                Fail::because('Could not create the blocked socket directory fixture.');
            }

            $socket = ServerSocket::listen($blocked);

            Expect::that($socket->address)
                ->because('the orchestrator MUST use TCP when its Unix socket cannot open')
                ->toStartWith('tcp://127.0.0.1:')
                ->and(\is_resource($socket->stream()))
                ->toBeTrue();
        } finally {
            $socket?->close();

            if (\is_string($blocked)) {
                \unlink($blocked);
            }
        }
    }
}
