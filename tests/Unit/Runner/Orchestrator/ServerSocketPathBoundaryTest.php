<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Orchestrator\ServerSocket;

final readonly class ServerSocketPathBoundaryTest
{
    private const int PORTABLE_PATH_BYTES = 100;
    private const string GENERATED_SUFFIX = '/gl-000000000000/s';

    #[Test]
    public function exactPortablePathLimitUsesAUnixSocket(): void
    {
        if (!\in_array('unix', \stream_get_transports(), true)) {
            throw new SkipTest('Unix stream sockets are not available');
        }

        $temporaryRoot = \realpath('/tmp');

        if (!\is_string($temporaryRoot) || !\is_writable($temporaryRoot)) {
            throw new SkipTest('A short writable temporary root is not available');
        }

        $prefix = \rtrim($temporaryRoot, '/') . '/greenlight-boundary-' . \bin2hex(\random_bytes(4)) . '-';
        $rootBytes = self::PORTABLE_PATH_BYTES - \strlen(self::GENERATED_SUFFIX);
        $paddingBytes = $rootBytes - \strlen($prefix);

        if ($paddingBytes < 1) {
            throw new SkipTest('The temporary root cannot form the portable socket path boundary');
        }

        $root = $prefix . \str_repeat('x', $paddingBytes);
        $socket = null;

        if (!@\mkdir($root, 0o700)) {
            Fail::because('Could not create the exact-length socket root.');
        }

        try {
            $socket = ServerSocket::listen($root);

            Expect::that($socket->address)
                ->because('a socket path at the portable byte limit MUST use Unix transport')
                ->toStartWith('unix://');
            Expect::that(\strlen(\substr($socket->address, \strlen('unix://'))))
                ->because('the fixture MUST exercise the exact portable path limit')
                ->toBe(self::PORTABLE_PATH_BYTES);
        } finally {
            $socket?->close();
            @\rmdir($root);
        }
    }
}
