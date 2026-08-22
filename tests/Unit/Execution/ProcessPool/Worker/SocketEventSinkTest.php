<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Event\TestClassStarted;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\SocketChannel;
use Greenlight\Execution\ProcessPool\Worker\SocketEventSink;
use Greenlight\Expect\Expect;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\ConnectedStreamPair;

final readonly class SocketEventSinkTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function emittedEventsCrossTheWorkerChannelInAnEventEnvelope(): void
    {
        [$senderStream, $receiverStream] = ConnectedStreamPair::open();
        $sender = new SocketChannel($senderStream);
        $receiver = new SocketChannel($receiverStream);
        $this->cleanup->defer($receiver->close(...));
        $this->cleanup->defer($sender->close(...));
        $sink = new SocketEventSink($sender);
        $event = new TestClassStarted('Acme\\ExampleTest', 1.0, 'worker-1');

        $sink->emit($event);
        $message = $receiver->poll();

        Expect::that($message)
            ->because('SocketEventSink MUST send EventEnvelope.')
            ->toBeInstanceOf(EventEnvelope::class);

        Expect::that($message->event)
            ->because('the worker event sink MUST transport the emitted event')
            ->toEqual($event);
    }

}
