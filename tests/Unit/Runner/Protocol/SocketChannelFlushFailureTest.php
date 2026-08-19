<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\StreamWrapperSandbox;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Tests\Fixture\Runner\Protocol\FlushFailureStream;

final readonly class SocketChannelFlushFailureTest
{
    private const string STREAM_SCHEME = 'greenlight-flush-failure';

    public function __construct(private StreamWrapperSandbox $streamWrappers) {}

    #[Test]
    public function aFailedFlushRejectsTheSend(): void
    {
        $this->streamWrappers->register(self::STREAM_SCHEME, FlushFailureStream::class);

        $stream = \fopen(self::STREAM_SCHEME . '://channel', 'w+');

        if ($stream === false) {
            Fail::because('The test could not open the flush-failure stream.');
        }

        $channel = new SocketChannel($stream);

        try {
            Expect::that(static function () use ($channel): void {
                $channel->send(new Drain());
            })
                ->because('a failed flush MUST reject an incomplete send')
                ->toThrow(
                    ProtocolError::class,
                    message: 'Malformed frame: peer closed the connection during a write.',
                );
        } finally {
            $channel->close();
        }
    }
}
