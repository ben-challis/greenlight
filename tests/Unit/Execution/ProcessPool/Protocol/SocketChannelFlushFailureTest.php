<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Drain;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Execution\ProcessPool\Protocol\SocketChannel;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Execution\ProcessPool\Protocol\FlushFailureStream;

final readonly class SocketChannelFlushFailureTest
{
    private const string STREAM_SCHEME = 'greenlight-flush-failure';

    public function __construct(
        private StreamWrappers $streamWrappers,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function aFailedFlushRejectsTheSend(): void
    {
        $this->streamWrappers->register(self::STREAM_SCHEME, FlushFailureStream::class);

        $stream = \fopen(self::STREAM_SCHEME . '://channel', 'w+');

        if ($stream === false) {
            Fail::because('The test could not open the flush-failure stream.');
        }

        $channel = new SocketChannel($stream);
        $this->cleanup->defer($channel->close(...));

        Expect::that(static function () use ($channel): void {
            $channel->send(new Drain());
        })
            ->because('a failed flush MUST reject an incomplete send')
            ->toThrow(
                ProtocolError::class,
                message: 'Malformed frame: peer closed the connection during a write.',
            );
    }
}
