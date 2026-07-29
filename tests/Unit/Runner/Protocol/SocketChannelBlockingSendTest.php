<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Protocol\JsonFrameCodec;
use Greenlight\Runner\Protocol\MessageRegistry;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Tests\Fixture\Runner\Protocol\BlockingWriteStream;

final readonly class SocketChannelBlockingSendTest
{
    private const string STREAM_SCHEME = 'greenlight-blocking-write';

    #[Test]
    public function sendRestoresBlockingModeAfterPoll(): void
    {
        if (!\stream_wrapper_register(self::STREAM_SCHEME, BlockingWriteStream::class)) {
            Fail::because('The test could not register the blocking-write stream.');
        }

        $stream = \fopen(self::STREAM_SCHEME . '://channel', 'r+');

        if ($stream === false) {
            \stream_wrapper_unregister(self::STREAM_SCHEME);
            Fail::because('The test could not open the blocking-write stream.');
        }

        $channel = new SocketChannel($stream);

        try {
            Expect::that($channel->poll())
                ->because('poll() MUST leave an empty channel without a message')
                ->toBeNull();

            $channel->send(new Drain());

            $expected = new JsonFrameCodec()->encode(MessageRegistry::envelope(new Drain()));

            Expect::that(BlockingWriteStream::contents())
                ->because('send() MUST restore blocking mode and write the complete frame')
                ->toBe($expected);
        } finally {
            $channel->close();
            \stream_wrapper_unregister(self::STREAM_SCHEME);
        }
    }
}
