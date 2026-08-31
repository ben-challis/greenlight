<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Internal\Event;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Event\Event;
use Greenlight\Event\RunFinished;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;
use Greenlight\Event\TestFinished;
use Greenlight\Event\TestStarted;
use Greenlight\Event\WireEvent;
use Greenlight\Event\WorkerSpawned;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Event\EventCodec;
use Greenlight\Internal\Event\EventCodecFailed;
use Greenlight\Tests\Unit\Reporting\CannedStream;

final class EventCodecTest
{
    #[Test]
    public function publishedTagsKeepTheirEventClasses(): void
    {
        Expect::that(EventCodec::tags())
            ->because('published event tags MUST keep their machine-readable meanings')
            ->toBe([
                'run-started' => RunStarted::class,
                'run-finished' => RunFinished::class,
                'class-started' => TestClassStarted::class,
                'class-finished' => TestClassFinished::class,
                'test-started' => TestStarted::class,
                'test-finished' => TestFinished::class,
                'worker-spawned' => WorkerSpawned::class,
            ]);
    }

    #[Test]
    public function taggedPayloadsRoundTripThroughOneCodec(): void
    {
        foreach (CannedStream::events() as $event) {
            $decoded = EventCodec::fromTagged(EventCodec::toTagged($event));

            Expect::that($decoded::class)->toBe($event::class);
            Expect::that($decoded->occurredAt)->toBe($event->occurredAt);
        }
    }

    #[Test]
    public function jsonEncodingUsesTheDocumentedVersionOneEnvelope(): void
    {
        $event = CannedStream::events()[0];

        Expect::that(EventCodec::encodeJsonLine($event))->toBe(
            "{\"v\":1,\"event\":\"run-started\",\"data\":{\"runId\":\"run-1\",\"plannedTests\":6,\"workers\":2,\"occurredAt\":1750000000.5,\"artifactsDirectory\":null}}\n",
        );
    }

    #[Test]
    public function jsonDecodingAcceptsVersionOne(): void
    {
        $event = EventCodec::decodeJsonLine(\sprintf(
            '{"v":%d,"event":"run-started","data":{"runId":"run-1","plannedTests":1,"workers":1,"occurredAt":1,"artifactsDirectory":null}}',
            1,
        ));

        Expect::that($event::class)->toBe(RunStarted::class);
        Expect::that($event->occurredAt)->toBe(1.0);
    }

    #[Test]
    public function malformedJsonIsRejectedByTheCodec(): void
    {
        Expect::that(static fn(): Event => EventCodec::decodeJsonLine('{'))
            ->toThrow(EventCodecFailed::class, message: 'The JSONL line is not valid JSON.');
    }

    #[Test]
    public function malformedJsonEnvelopesAreRejectedByTheCodec(): void
    {
        Expect::that(static fn(): Event => EventCodec::decodeJsonLine('{"event":7,"data":[]}'))
            ->toThrow(EventCodecFailed::class, message: 'The JSONL line does not contain an event envelope.');
    }

    #[Test]
    #[DataSet('malformedJsonEnvelopeShapes')]
    public function malformedJsonEnvelopeShapesAreRejectedByTheCodec(string $line): void
    {
        Expect::that(static fn(): Event => EventCodec::decodeJsonLine($line))
            ->toThrow(EventCodecFailed::class, message: 'The JSONL line does not contain an event envelope.');
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function malformedJsonEnvelopeShapes(): iterable
    {
        yield 'scalar' => ['null'];
        yield 'list' => ['[true]'];
        yield 'numeric object key' => ['{"0":true,"v":1,"event":"run-started","data":{}}'];
    }

    #[Test]
    public function malformedTaggedEventIdentifiersAreRejectedByTheCodec(): void
    {
        Expect::that(static fn(): WireEvent => EventCodec::fromTagged(['data' => []]))
            ->toThrow(EventCodecFailed::class, message: 'Wire payload is missing the "event" key.');
    }

    #[Test]
    public function malformedTaggedEventDataIsRejectedByTheCodec(): void
    {
        Expect::that(static fn(): WireEvent => EventCodec::fromTagged([
            'event' => 'run-started',
            'data' => [true],
        ]))->toThrow(EventCodecFailed::class, message: 'Wire payload key "data" must be a map, got array.');
    }

    #[Test]
    public function unsupportedJsonVersionsAreRejectedByTheCodec(): void
    {
        Expect::that(static fn(): Event => EventCodec::decodeJsonLine('{"v":2,"event":"run-started","data":{}}'))
            ->toThrow(EventCodecFailed::class, message: 'Unsupported JSONL version 2.');
    }

    #[Test]
    public function unknownTagsAreRejectedByTheCodec(): void
    {
        Expect::that(static fn(): Event => EventCodec::decodeJsonLine('{"v":1,"event":"future-event","data":{}}'))
            ->toThrow(EventCodecFailed::class, message: 'Unknown event type "future-event".');
    }

    #[Test]
    public function invalidKnownEventPayloadsAreRejectedByTheCodec(): void
    {
        Expect::that(static fn(): Event => EventCodec::decodeJsonLine('{"v":1,"event":"run-started","data":{}}'))
            ->toThrow(EventCodecFailed::class, message: 'Wire payload is missing the "runId" key.');
    }

    #[Test]
    public function unmappedEventsAreRejectedByTheCodec(): void
    {
        $event = new class implements Event, Fake {
            public float $occurredAt = 1.0;
        };

        Expect::that(static fn(): array => EventCodec::toTagged($event))
            ->toThrow(
                EventCodecFailed::class,
                message: \sprintf('Event "%s" has no stable tag.', $event::class),
            );
    }

    #[Test]
    public function unmappedWireEventsAreRejectedByTheCodec(): void
    {
        $event = new class implements WireEvent, Fake {
            public float $occurredAt = 1.0;

            #[\Override]
            public function toWire(): array
            {
                return [];
            }

            #[\Override]
            public static function fromWire(array $payload): static
            {
                return new self();
            }
        };

        Expect::that(static fn(): array => EventCodec::toTagged($event))
            ->toThrow(
                EventCodecFailed::class,
                message: \sprintf('Event "%s" has no stable tag.', $event::class),
            );
    }
}
