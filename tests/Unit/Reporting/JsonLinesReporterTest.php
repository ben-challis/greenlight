<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\Event;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Event\EventCodec;
use Greenlight\Reporting\JsonLinesReporter;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Tests\Support\JsonWire;

final class JsonLinesReporterTest
{
    #[Test]
    public function everyEventBecomesOneVersionedLine(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new JsonLinesReporter($output));

        $buffer = $output->buffer();
        $events = CannedStream::events();

        Expect::that($buffer)->because('every event becomes one versioned line')->toEndWith("\n");

        $lines = \explode("\n", \rtrim($buffer, "\n"));

        Expect::that($lines)->because('every event becomes one versioned line')->toHaveCount(\count($events));

        $tags = EventCodec::tags();

        foreach ($lines as $index => $line) {
            $decoded = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            $event = $events[$index];

            Expect::that($decoded)->toHaveKey('v')
                ->toHaveKey('event')
                ->toHaveKey('data');

            if (!\is_array($decoded)) {
                continue;
            }

            $expectedData = JsonWire::roundTrip($event->toWire());

            Expect::that($decoded['v'])->toBe(3);
            Expect::that($decoded['event'])->toBe(\array_search($event::class, $tags, true));
            Expect::that($decoded['data'])->toEqual($expectedData);
        }
    }

    #[Test]
    public function linesRoundTripBackToEventsThroughTheCodec(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new JsonLinesReporter($output));

        $events = CannedStream::events();

        foreach (\explode("\n", \rtrim($output->buffer(), "\n")) as $index => $line) {
            $restored = EventCodec::decodeJsonLine($line);

            Expect::that($restored::class)->toBe($events[$index]::class);
            Expect::that($restored->occurredAt)->toBe($events[$index]->occurredAt);
        }
    }

    #[Test]
    public function firstLineMatchesTheDocumentedEnvelopeShape(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new JsonLinesReporter($output));

        $lines = \explode("\n", $output->buffer());

        Expect::that($lines[0])->because('first line matches the documented envelope shape')->toBe(
            '{"v":3,"event":"run-started","data":{"runId":"run-1","plannedTests":6,"workers":2,"occurredAt":1750000000.5,"artifactsDirectory":null}}',
        );
    }

    #[Test]
    public function anUnmappedEventIsRejected(): void
    {
        $reporter = new JsonLinesReporter(new BufferOutput());

        $event = new class implements Event {
            public float $occurredAt = 1.0;
        };

        Expect::that(static fn() => $reporter->onEvent($event))
            ->because('a custom event only needs the public event interface and cannot enter JSONL')
            ->toThrow(
                ReportGenerationFailed::class,
                message: \sprintf(
                    'Event "%s" has no stable tag. Add the event to the tag map before Greenlight writes it.',
                    $event::class,
                ),
            );
    }
}
