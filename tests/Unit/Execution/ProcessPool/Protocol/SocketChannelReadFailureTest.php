<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Execution\ProcessPool\Protocol\SocketChannel;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Execution\ProcessPool\Protocol\ReadFailureStream;

final readonly class SocketChannelReadFailureTest
{
    private const string STREAM_SCHEME = 'greenlight-read-failure';

    public function __construct(
        private StreamWrappers $streamWrappers,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function aFailedReadRejectsTheChannel(): void
    {
        $this->streamWrappers->register(self::STREAM_SCHEME, ReadFailureStream::class);

        $stream = \fopen(self::STREAM_SCHEME . '://channel', 'r+');

        if ($stream === false) {
            Fail::because('The test could not open the read-failure stream.');
        }

        $channel = new SocketChannel($stream);
        $this->cleanup->defer($channel->close(...));

        Expect::that(static fn() => $channel->poll())
            ->because('a failed read MUST not masquerade as an idle channel')
            ->toThrow(
                ProtocolError::class,
                message: 'Malformed frame: peer connection failed during a read.',
            );
    }
}
