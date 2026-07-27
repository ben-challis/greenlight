<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Protocol\SocketChannel;

final class SocketChannelTest
{
    #[Test]
    public function anExternallyClosedStreamEndsPollingAndRejectsWrites(): void
    {
        [$stream, $peer] = $this->socketPair();
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

    /**
     * @return array{resource, resource}
     */
    private function socketPair(): array
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);

        if ($pair === false || \count($pair) !== 2 || !isset($pair[0], $pair[1])) {
            Fail::because('Expected stream_socket_pair() to create a connected socket pair.');
        }

        return [$pair[0], $pair[1]];
    }
}
