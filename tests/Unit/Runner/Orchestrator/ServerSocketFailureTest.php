<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\ServerSocket;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Tests\Fixture\Runner\Orchestrator\ControlledServerSocketRuntime;

final readonly class ServerSocketFailureTest
{
    #[Test]
    public function failureOfBothTransportsReportsTheTcpDiagnostic(): void
    {
        $runtime = new ControlledServerSocketRuntime(tcpOpens: false);

        Expect::that(static fn(): ServerSocket => ServerSocket::listen('/tmp', $runtime))
            ->because('failure of both listener transports MUST report the TCP failure')
            ->toThrow(
                ProtocolError::class,
                message: 'Malformed frame: Greenlight did not open an orchestrator socket: '
                    . 'the fixture rejected the TCP listener.',
            )
            ->and($runtime->unixDirectoryExists())
            ->because('a failed Unix listener MUST remove its generated directory')
            ->toBeFalse();
    }

    #[Test]
    public function unresolvedTcpAddressClosesTheListener(): void
    {
        $runtime = new ControlledServerSocketRuntime(tcpOpens: true);

        Expect::that(static fn(): ServerSocket => ServerSocket::listen('/tmp', $runtime))
            ->because('an unresolved listener address MUST reject the listener')
            ->toThrow(
                ProtocolError::class,
                message: 'Malformed frame: Greenlight did not resolve the orchestrator socket address.',
            )
            ->and($runtime->tcpServerIsOpen())
            ->because('an unresolved listener address MUST close its stream')
            ->toBeFalse();
    }
}
