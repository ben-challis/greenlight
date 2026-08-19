<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\StreamWrapperSandbox;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Tests\Fixture\Runner\Protocol\ReadFailureStream;

final readonly class SocketChannelReadFailureTest
{
    private const string STREAM_SCHEME = 'greenlight-read-failure';

    public function __construct(private StreamWrapperSandbox $streamWrappers) {}

    #[Test]
    public function aFailedReadRejectsTheChannel(): void
    {
        $this->streamWrappers->register(self::STREAM_SCHEME, ReadFailureStream::class);

        $stream = \fopen(self::STREAM_SCHEME . '://channel', 'r+');

        if ($stream === false) {
            Fail::because('The test could not open the read-failure stream.');
        }

        $channel = new SocketChannel($stream);

        try {
            Expect::that(static fn() => $channel->poll())
                ->because('a failed read MUST not masquerade as an idle channel')
                ->toThrow(
                    ProtocolError::class,
                    message: 'Malformed frame: peer connection failed during a read.',
                );
        } finally {
            $channel->close();
        }
    }
}
