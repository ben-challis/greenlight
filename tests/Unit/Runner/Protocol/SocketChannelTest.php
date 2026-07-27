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
    public function receiveTimeoutLeavesTheChannelOpenForLaterMessages(): void
    {
        [$stream, $peer] = $this->socketPair();
        $receiver = new SocketChannel($stream);
        $sender = new SocketChannel($peer);

        try {
            Expect::that($receiver->receive(0.0))
                ->because('an empty receive can reach its deadline')
                ->toBeNull()
                ->and($receiver->isEof())
                ->because('a receive deadline MUST NOT mark an open channel as EOF')
                ->toBeFalse();

            $sender->send(new Drain());

            Expect::that($receiver->receive(0.0))
                ->because('a channel remains usable after a receive deadline')
                ->toBeInstanceOf(Drain::class);
        } finally {
            $sender->close();
            $receiver->close();
        }
    }

    #[Test]
    public function aCompleteFinalFrameIsDeliveredBeforeCleanEof(): void
    {
        [$stream, $peer] = $this->socketPair();
        $receiver = new SocketChannel($stream);
        $sender = new SocketChannel($peer);

        try {
            $sender->send(new Drain());
            $sender->close();

            Expect::that($receiver->poll())
                ->because('a complete final frame MUST be delivered before peer EOF')
                ->toBeInstanceOf(Drain::class)
                ->and($receiver->poll())
                ->because('the channel reaches clean EOF after the final frame')
                ->toBeNull()
                ->and($receiver->isEof())
                ->toBeTrue();
        } finally {
            $sender->close();
            $receiver->close();
        }
    }

    #[Test]
    public function peerEofRejectsAnIncompleteFrame(): void
    {
        [$stream, $peer] = $this->socketPair();
        $channel = new SocketChannel($stream);

        try {
            \fwrite($peer, \pack('N', 10) . 'abc');
            \fclose($peer);

            Expect::that($channel->poll())
                ->because('the first poll reads the incomplete frame')
                ->toBeNull();

            Expect::that(static fn(): mixed => $channel->poll())
                ->because('peer EOF MUST reject an incomplete frame')
                ->toThrow(
                    ProtocolError::class,
                    message: 'Malformed frame: peer closed the connection with an incomplete frame.',
                );
        } finally {
            $channel->close();

            if (\is_resource($peer)) {
                \fclose($peer);
            }
        }
    }

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
