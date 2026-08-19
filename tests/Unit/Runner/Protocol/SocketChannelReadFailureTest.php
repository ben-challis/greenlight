<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Tests\Fixture\Runner\Protocol\ReadFailureStream;

final readonly class SocketChannelReadFailureTest
{
    private const string STREAM_SCHEME = 'greenlight-read-failure';

    #[Test]
    public function aFailedReadRejectsTheChannel(): void
    {
        if (!\stream_wrapper_register(self::STREAM_SCHEME, ReadFailureStream::class)) {
            Fail::because('The test could not register the read-failure stream.');
        }

        $stream = \fopen(self::STREAM_SCHEME . '://channel', 'r+');

        if ($stream === false) {
            \stream_wrapper_unregister(self::STREAM_SCHEME);
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
            \stream_wrapper_unregister(self::STREAM_SCHEME);
        }
    }
}
