<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\SocketChannel;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Execution\ProcessPool\Protocol\EofReadFailureStream;

final readonly class SocketChannelEofReadFailureTest
{
    private const string STREAM_SCHEME = 'greenlight-eof-read-failure';

    public function __construct(
        private StreamWrappers $streamWrappers,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function aFailedReadAtPeerEofClosesTheChannelCleanly(): void
    {
        $this->streamWrappers->register(self::STREAM_SCHEME, EofReadFailureStream::class);

        $stream = \fopen(self::STREAM_SCHEME . '://channel', 'r+');

        if ($stream === false) {
            Fail::because('The test could not open the EOF read-failure stream.');
        }

        $channel = new SocketChannel($stream);
        $this->cleanup->defer($channel->close(...));

        Expect::that($channel->poll())
            ->because('a failed read that reaches peer EOF MUST end polling cleanly')
            ->toBeNull();
        Expect::that($channel->isEof())->toBeTrue();
    }
}
