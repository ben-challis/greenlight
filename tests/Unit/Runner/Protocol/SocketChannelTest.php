<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Protocol\Messages\Drain;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Tests\Fixture\Runner\Protocol\UnselectableStream;
use Greenlight\Tests\Support\ConnectedStreamPair;

final class SocketChannelTest
{
    private const string UNSELECTABLE_SCHEME = 'greenlight-unselectable';

    #[Test]
    public function receiveTimeoutLeavesTheChannelOpenForLaterMessages(): void
    {
        [$stream, $peer] = ConnectedStreamPair::open();
        $receiver = new SocketChannel($stream);
        $sender = new SocketChannel($peer);

        try {
            Expect::that($receiver->receive(0.0))
                ->because('an empty receive can reach its deadline')
                ->toBeNull();
            Expect::that($receiver->isEof())
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
        [$stream, $peer] = ConnectedStreamPair::open();
        $receiver = new SocketChannel($stream);
        $sender = new SocketChannel($peer);

        try {
            $sender->send(new Drain());
            $sender->close();

            Expect::that($receiver->poll())
                ->because('a complete final frame MUST be delivered before peer EOF')
                ->toBeInstanceOf(Drain::class);
            Expect::that($receiver->poll())
                ->because('the channel reaches clean EOF after the final frame')
                ->toBeNull();
            Expect::that($receiver->isEof())
                ->toBeTrue();
        } finally {
            $sender->close();
            $receiver->close();
        }
    }

    #[Test]
    public function peerEofRejectsAnIncompleteFrame(): void
    {
        [$stream, $peer] = ConnectedStreamPair::open();
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

            Expect::that(static fn(): mixed => $channel->poll())
                ->because('an incomplete frame MUST remain invalid after it is reported')
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
        [$stream, $peer] = ConnectedStreamPair::open();
        $channel = new SocketChannel($stream);

        try {
            \fclose($stream);

            Expect::that($channel->poll())
                ->because('polling an externally closed stream reaches EOF')
                ->toBeNull();
            Expect::that($channel->isEof())
                ->toBeTrue();
            Expect::that($channel->poll())
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

    #[Test]
    public function aSelectFailureEndsTheReceiveWithoutClosingTheChannel(): void
    {
        if (!\stream_wrapper_register(self::UNSELECTABLE_SCHEME, UnselectableStream::class)) {
            Fail::because('The test could not register the unselectable stream.');
        }

        $stream = \fopen(self::UNSELECTABLE_SCHEME . '://channel', 'r+');

        if ($stream === false) {
            \stream_wrapper_unregister(self::UNSELECTABLE_SCHEME);
            Fail::because('The test could not open the unselectable stream.');
        }

        $channel = new SocketChannel($stream);

        try {
            Expect::that($channel->receive(1.0))
                ->because('a stream-select failure MUST end the receive wait')
                ->toBeNull();
            Expect::that($channel->isEof())
                ->because('a stream-select failure MUST NOT mark the channel as EOF')
                ->toBeFalse();
        } finally {
            $channel->close();
            \stream_wrapper_unregister(self::UNSELECTABLE_SCHEME);
        }
    }

}
