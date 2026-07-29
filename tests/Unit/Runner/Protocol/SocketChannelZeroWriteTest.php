<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Tests\Fixture\Runner\Protocol\ZeroWriteStream;

final readonly class SocketChannelZeroWriteTest
{
    private const string STREAM_SCHEME = 'greenlight-zero-write';

    #[Test]
    public function aZeroByteWriteRejectsTheSend(): void
    {
        if (!\stream_wrapper_register(self::STREAM_SCHEME, ZeroWriteStream::class)) {
            Fail::because('The test could not register the zero-write stream.');
        }

        $stream = \fopen(self::STREAM_SCHEME . '://channel', 'w+');

        if ($stream === false) {
            \stream_wrapper_unregister(self::STREAM_SCHEME);
            Fail::because('The test could not open the zero-write stream.');
        }

        $channel = new SocketChannel($stream);

        try {
            Expect::that(static function () use ($channel): void {
                $channel->send(new Drain());
            })
                ->because('a zero-byte write MUST reject an incomplete send')
                ->toThrow(
                    ProtocolError::class,
                    message: 'Malformed frame: peer closed the connection during a write.',
                );
        } finally {
            $channel->close();
            \stream_wrapper_unregister(self::STREAM_SCHEME);
        }
    }
}
