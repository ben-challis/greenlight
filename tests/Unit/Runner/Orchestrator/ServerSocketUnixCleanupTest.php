<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Orchestrator\ServerSocket;

final readonly class ServerSocketUnixCleanupTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function closingAUnixListenerRemovesItsOwnedSocketDirectory(): void
    {
        $root = $this->tempDirectory->path();
        $socket = ServerSocket::listen($root);
        $closed = false;

        try {
            Expect::that($socket->address)
                ->because('a writable temporary root MUST use the Unix listener')
                ->toStartWith('unix://');

            $path = \substr($socket->address, \strlen('unix://'));
            $directory = \dirname($path);
            $client = \stream_socket_client($socket->address);

            try {
                Expect::that(\is_resource($client))
                    ->because('the Unix listener MUST accept connections at its published address')
                    ->toBeTrue()
                    ->and(\is_dir($directory))
                    ->toBeTrue();
            } finally {
                if (\is_resource($client)) {
                    \fclose($client);
                }
            }

            $closed = true;
            $socket->close();

            Expect::that(\is_dir($directory))
                ->because('closing the listener MUST remove its generated directory')
                ->toBeFalse()
                ->and(\is_dir($root))
                ->because('closing the listener MUST leave its supplied temporary root')
                ->toBeTrue();
        } finally {
            if (!$closed) {
                $socket->close();
            }
        }
    }

    #[Test]
    public function aRootBeyondThePortableUnixPathLimitUsesTcpWithoutCreatingIt(): void
    {
        $longRoot = $this->tempDirectory->path() . '/' . \str_repeat('x', 100);
        $socket = ServerSocket::listen($longRoot);

        try {
            Expect::that($socket->address)
                ->because('an oversized Unix socket path MUST use the TCP fallback')
                ->toStartWith('tcp://127.0.0.1:')
                ->and(\is_dir($longRoot))
                ->because('the rejected Unix socket path MUST NOT create directories')
                ->toBeFalse();
        } finally {
            $socket->close();
        }
    }
}
