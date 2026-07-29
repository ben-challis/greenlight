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
use Greenlight\Tests\Fixture\Runner\Protocol\PartialWriteStream;

final readonly class SocketChannelPartialWriteTest
{
    private const string SCHEME = 'greenlight-protocol-partial-write';

    #[Test]
    public function sendContinuesUntilTheCompleteFrameIsWritten(): void
    {
        if (!\stream_wrapper_register(self::SCHEME, PartialWriteStream::class)) {
            Fail::because('The test could not register the partial-write stream.');
        }

        $stream = \fopen(self::SCHEME . '://channel', 'wb');

        if ($stream === false) {
            \stream_wrapper_unregister(self::SCHEME);
            Fail::because('The test could not open the partial-write stream.');
        }

        $codec = new JsonFrameCodec();
        $message = new Drain();
        $channel = new SocketChannel($stream, $codec);

        try {
            $channel->send($message);

            Expect::that(PartialWriteStream::$written)
                ->because('send() MUST write the complete encoded frame')
                ->toBe($codec->encode(MessageRegistry::envelope($message)))
                ->and(PartialWriteStream::$writes)
                ->because('the fixture accepts at most two bytes from each write')
                ->toBeGreaterThan(1);
        } finally {
            $channel->close();
            \stream_wrapper_unregister(self::SCHEME);
        }
    }
}
