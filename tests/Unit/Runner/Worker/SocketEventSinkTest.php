<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\SuiteStarted;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Protocol\Messages\EventEnvelope;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Runner\Worker\SocketEventSink;

final class SocketEventSinkTest
{
    #[Test]
    public function emittedEventsCrossTheWorkerChannelInAnEventEnvelope(): void
    {
        [$senderStream, $receiverStream] = $this->socketPair();
        $sender = new SocketChannel($senderStream);
        $receiver = new SocketChannel($receiverStream);
        $sink = new SocketEventSink($sender);
        $event = new SuiteStarted('unit', 1.0);

        try {
            $sink->emit($event);
            $message = $receiver->poll();

            if (!$message instanceof EventEnvelope) {
                Fail::because(\sprintf(
                    'Expected SocketEventSink to send EventEnvelope, got %s.',
                    \get_debug_type($message),
                ));
            }

            Expect::that($message->event)
                ->because('the worker event sink MUST transport the emitted event')
                ->toEqual($event);
        } finally {
            $sender->close();
            $receiver->close();
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
