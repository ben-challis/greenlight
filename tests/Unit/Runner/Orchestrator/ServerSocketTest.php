<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\Cleanup;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Orchestrator\ServerSocket;

final readonly class ServerSocketTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function tcpFallbackListensWhenTheUnixSocketCannotOpen(): void
    {
        $temporaryDirectory = \is_dir('/tmp') ? '/tmp' : \sys_get_temp_dir();
        $blocked = \tempnam($temporaryDirectory, 'greenlight-socket-');

        if (!\is_string($blocked)) {
            Fail::because('Could not create the blocked socket directory fixture.');
        }

        $this->cleanup->defer(static fn(): bool => \unlink($blocked));
        $socket = ServerSocket::listen($blocked);
        $this->cleanup->defer($socket->close(...));

        Expect::that($socket->address)
            ->because('the orchestrator MUST use TCP when its Unix socket cannot open')
            ->toStartWith('tcp://127.0.0.1:');
        Expect::that(\is_resource($socket->stream()))
            ->toBeTrue();
    }
}
