<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\ServerSocket;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Runner\Orchestrator\ControlledServerSocketRuntime;
use Greenlight\Tests\Fixture\Runner\Orchestrator\TruncatingServerSocketRuntime;

final readonly class ServerSocketFailureTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

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
            );
        Expect::that($runtime->unixDirectoryExists())
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
            );
        Expect::that($runtime->tcpServerIsOpen())
            ->because('an unresolved listener address MUST close its stream')
            ->toBeFalse();
    }

    #[Test]
    public function truncatedUnixAddressClosesTheListenerAndUsesTcp(): void
    {
        $runtime = new TruncatingServerSocketRuntime($this->tempDirectory->path() . '/truncated.sock');
        $socket = ServerSocket::listen($this->tempDirectory->path(), $runtime);

        try {
            Expect::that($socket->address)
                ->because('a truncated Unix address MUST use the TCP listener')
                ->toStartWith('tcp://127.0.0.1:');
            Expect::that($runtime->unixServerIsOpen())
                ->because('Greenlight MUST close a Unix listener that has a truncated address')
                ->toBeFalse();
            Expect::that($runtime->unixDirectoryExists())
                ->because('Greenlight MUST remove the rejected Unix listener directory')
                ->toBeFalse();
        } finally {
            $socket->close();
        }
    }
}
