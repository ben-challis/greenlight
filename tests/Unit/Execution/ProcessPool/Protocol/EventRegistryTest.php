<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Event\Event;
use Greenlight\Execution\ProcessPool\Protocol\EventRegistry;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Expect\Expect;

final class EventRegistryTest
{
    #[Test]
    public function anUnknownEventTagIsRejected(): void
    {
        Expect::that(static fn(): Event => EventRegistry::fromTagged([
            'event' => 'future-event',
            'data' => [],
        ]))
            ->because('an unknown event tag cannot cross the worker protocol')
            ->toThrow(
                ProtocolError::class,
                message: 'Unknown event type "future-event".',
            );
    }

    #[Test]
    public function anUnmappedEventClassIsRejected(): void
    {
        $event = new class implements Event, Fake {
            public float $occurredAt = 1.0;

            #[\Override]
            public function toWire(): array
            {
                return ['occurredAt' => $this->occurredAt];
            }

            #[\Override]
            public static function fromWire(array $payload): static
            {
                throw new \LogicException('Not deserializable.');
            }
        };

        Expect::that(static fn(): array => EventRegistry::toTagged($event))
            ->because('an event class needs a stable protocol tag')
            ->toThrow(
                ProtocolError::class,
                message: \sprintf('Unknown event type "%s".', $event::class),
            );
    }
}
