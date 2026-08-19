<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Tests\Fixture\Runner\Protocol\EofReadFailureStream;

final readonly class SocketChannelEofReadFailureTest
{
    private const string STREAM_SCHEME = 'greenlight-eof-read-failure';

    #[Test]
    public function aFailedReadAtPeerEofClosesTheChannelCleanly(): void
    {
        if (!\stream_wrapper_register(self::STREAM_SCHEME, EofReadFailureStream::class)) {
            Fail::because('The test could not register the EOF read-failure stream.');
        }

        $stream = \fopen(self::STREAM_SCHEME . '://channel', 'r+');

        if ($stream === false) {
            \stream_wrapper_unregister(self::STREAM_SCHEME);
            Fail::because('The test could not open the EOF read-failure stream.');
        }

        $channel = new SocketChannel($stream);

        try {
            Expect::that($channel->poll())
                ->because('a failed read that reaches peer EOF MUST end polling cleanly')
                ->toBeNull();
            Expect::that($channel->isEof())->toBeTrue();
        } finally {
            $channel->close();
            \stream_wrapper_unregister(self::STREAM_SCHEME);
        }
    }
}
