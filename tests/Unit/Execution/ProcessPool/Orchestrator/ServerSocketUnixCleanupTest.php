<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\ServerSocket;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\SkipTest;

use function Greenlight\expect;

final readonly class ServerSocketUnixCleanupTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function closingAUnixListenerRemovesItsOwnedSocketDirectory(): void
    {
        if (!\in_array('unix', \stream_get_transports(), true)) {
            throw new SkipTest('Unix stream sockets are not available');
        }

        $temporaryRoot = \realpath('/tmp');

        if (!\is_string($temporaryRoot) || !\is_writable($temporaryRoot)) {
            throw new SkipTest('A short writable temporary root is not available');
        }

        $root = \rtrim($temporaryRoot, '/') . '/gl-cleanup-' . \bin2hex(\random_bytes(4));

        if (!@\mkdir($root, 0o700)) {
            throw new SkipTest('A short writable temporary root is not available');
        }

        $socket = null;
        $closed = false;

        try {
            $socket = ServerSocket::listen($root);

            expect($socket->address)
                ->because('a writable temporary root MUST use the Unix listener')
                ->toStartWith('unix://');

            $path = \substr($socket->address, \strlen('unix://'));
            $directory = \dirname($path);
            $client = \stream_socket_client($socket->address);

            try {
                expect(\is_resource($client))
                    ->because('the Unix listener MUST accept connections at its published address')
                    ->toBeTrue();
                expect(\is_dir($directory))
                    ->because('the Unix listener MUST create its socket directory')
                    ->toBeTrue();
            } finally {
                if (\is_resource($client)) {
                    \fclose($client);
                }
            }

            $closed = true;
            $socket->close();

            expect(\is_dir($directory))
                ->because('closing the listener MUST remove its generated directory')
                ->toBeFalse();
            expect(\is_dir($root))
                ->because('closing the listener MUST leave its supplied temporary root')
                ->toBeTrue();
            expect()->calling(static function () use ($socket): void {
                $socket->close();
            })
                ->because('listener cleanup MUST tolerate a repeated defensive close')
                ->not()->toThrow(\Throwable::class);
        } finally {
            if (!$closed) {
                $socket?->close();
            }

            @\rmdir($root);
        }
    }

    #[Test]
    public function aMissingTemporaryRootUsesTcpWithoutCreatingIt(): void
    {
        $missingRoot = $this->tempDirectory->path() . '/missing';
        $socket = ServerSocket::listen($missingRoot);

        try {
            expect($socket->address)
                ->because('a missing temporary root MUST use the TCP listener')
                ->toStartWith('tcp://127.0.0.1:');
            expect(\is_dir($missingRoot))
                ->because('the Unix listener MUST NOT create the supplied temporary root')
                ->toBeFalse();
        } finally {
            $socket->close();
        }
    }
}
