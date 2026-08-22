<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\ServerSocket;
use Greenlight\Execution\ProcessPool\Orchestrator\ServerSocketRuntime;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Execution\ProcessPool\Orchestrator\ControlledServerSocketRuntime;
use Greenlight\Tests\Fixture\Execution\ProcessPool\Orchestrator\TruncatingServerSocketRuntime;

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
    public function listenerThrowableBecomesAProtocolError(): void
    {
        $cause = new \RuntimeException('The fixture listener failed.');
        $runtime = new readonly class ($cause) implements ServerSocketRuntime {
            public function __construct(private \Throwable $cause) {}

            #[\Override]
            public function listen(string $address, ?string &$errorMessage): never
            {
                throw $this->cause;
            }

            #[\Override]
            public function name($server): string|false
            {
                return false;
            }
        };

        Expect::that(fn(): ServerSocket => ServerSocket::listen($this->tempDirectory->path(), $runtime))
            ->because('a listener throwable MUST not escape the worker protocol seam')
            ->toThrow(
                static function (ProtocolError $error) use ($cause): void {
                    Expect::that($error->getMessage())
                        ->toBe('Greenlight could not open the orchestrator socket.');
                    Expect::that($error->getPrevious())
                        ->because('the protocol error MUST preserve the listener error')
                        ->toBe($cause);
                },
            );
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
