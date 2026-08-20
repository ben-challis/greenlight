<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\SuiteStarted;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\Messages\EventEnvelope;
use Greenlight\Runner\Protocol\SocketChannel;
use Greenlight\Runner\Worker\SocketEventSink;
use Greenlight\Tests\Support\ConnectedStreamPair;

final class SocketEventSinkTest
{
    #[Test]
    public function emittedEventsCrossTheWorkerChannelInAnEventEnvelope(): void
    {
        [$senderStream, $receiverStream] = ConnectedStreamPair::open();
        $sender = new SocketChannel($senderStream);
        $receiver = new SocketChannel($receiverStream);
        $sink = new SocketEventSink($sender);
        $event = new SuiteStarted('unit', 1.0);

        try {
            $sink->emit($event);
            $message = $receiver->poll();

            Expect::that($message)
                ->because('SocketEventSink MUST send EventEnvelope.')
                ->toBeInstanceOf(EventEnvelope::class);

            Expect::that($message->event)
                ->because('the worker event sink MUST transport the emitted event')
                ->toEqual($event);
        } finally {
            $sender->close();
            $receiver->close();
        }
    }

}
