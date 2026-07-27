<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\Event;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\EventRegistry;
use Greenlight\Runner\Protocol\ProtocolError;

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
