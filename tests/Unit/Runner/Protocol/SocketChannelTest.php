<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Protocol\SocketChannel;

final class SocketChannelTest
{
    #[Test]
    public function anExternallyClosedStreamEndsPollingAndRejectsWrites(): void
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \assert(\is_array($pair));
        [$stream, $peer] = $pair;
        $channel = new SocketChannel($stream);

        try {
            \fclose($stream);

            Expect::that($channel->poll())
                ->because('polling an externally closed stream reaches EOF')
                ->toBeNull()
                ->and($channel->isEof())
                ->toBeTrue()
                ->and($channel->poll())
                ->toBeNull();

            Expect::that(static function () use ($channel): void {
                $channel->send(new Drain());
            })
                ->because('a closed channel MUST reject writes')
                ->toThrow(ProtocolError::class, message: 'Malformed frame: the channel is closed.');
        } finally {
            $channel->close();
            \fclose($peer);
        }
    }
}
