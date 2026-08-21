<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Runner\Protocol\ThrowingStream;

final readonly class SocketChannelThrowableTest
{
    private const string STREAM_SCHEME = 'greenlight-throwing-channel';

    public function __construct(
        private StreamWrappers $streamWrappers,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    #[DataSet('operations')]
    public function streamThrowablesBecomeProtocolErrors(string $operation): void
    {
        $this->streamWrappers->register(self::STREAM_SCHEME, ThrowingStream::class);

        $stream = \fopen(self::STREAM_SCHEME . '://channel', 'r+');

        if ($stream === false) {
            Fail::because('The test could not open the throwing stream.');
        }

        $channel = new SocketChannel($stream);
        $this->cleanup->defer($channel->close(...));
        $invoke = $operation === 'read'
            ? $channel->poll(...)
            : static fn() => $channel->send(new Drain());

        Expect::that($invoke)
            ->because('a stream throwable MUST not escape the worker protocol seam')
            ->toThrow(
                static function (ProtocolError $error) use ($operation): void {
                    Expect::that($error->getMessage())
                        ->toBe(\sprintf('Worker protocol stream %s failed.', $operation));
                    Expect::that($error->getPrevious())
                        ->because('the protocol error MUST preserve the stream error')
                        ->toBeInstanceOf(\RuntimeException::class);
                },
            );
    }

    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        yield 'read' => ['read'];
        yield 'write' => ['write'];
    }
}
