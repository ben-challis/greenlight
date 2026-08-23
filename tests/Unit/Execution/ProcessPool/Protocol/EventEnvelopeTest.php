<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Event\Event;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Expect\Expect;

final class EventEnvelopeTest
{
    #[Test]
    public function anUnknownEventTagIsAProtocolError(): void
    {
        Expect::that(static fn(): EventEnvelope => EventEnvelope::fromWire([
            'event' => 'future-event',
            'data' => [],
        ]))->toThrow(
            ProtocolError::class,
            message: 'Unknown event type "future-event".',
        );
    }

    #[Test]
    public function anUnmappedEventClassIsAProtocolError(): void
    {
        $event = new class implements Event, Fake {
            public float $occurredAt = 1.0;
        };

        Expect::that(static fn(): array => new EventEnvelope($event)->toWire())
            ->toThrow(
                ProtocolError::class,
                message: \sprintf('Unknown event type "%s".', $event::class),
            );
    }

    #[Test]
    public function aMalformedEventPayloadIsAProtocolError(): void
    {
        Expect::that(static fn(): EventEnvelope => EventEnvelope::fromWire([
            'event' => 'run-started',
            'data' => [],
        ]))->toThrow(
            ProtocolError::class,
            message: 'Wire payload is missing the "runId" key.',
        );
    }
}
