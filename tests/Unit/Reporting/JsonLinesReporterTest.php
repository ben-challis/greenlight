<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\Event;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\JsonLinesReporter;
use Greenlight\Reporting\ReportingError;

final class JsonLinesReporterTest
{
    #[Test]
    public function everyEventBecomesOneVersionedLine(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new JsonLinesReporter($output));

        $buffer = $output->buffer();
        $events = CannedStream::events();

        new Expect()->that($buffer)->toEndWith("\n");

        $lines = \explode("\n", \rtrim($buffer, "\n"));

        new Expect()->that($lines)->toHaveCount(\count($events));

        $tags = JsonLinesReporter::tags();

        foreach ($lines as $index => $line) {
            $decoded = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            $event = $events[$index];

            new Expect()
                ->that($decoded)->toHaveKey('v')
                ->and($decoded)->toHaveKey('event')
                ->and($decoded)->toHaveKey('data');

            if (!\is_array($decoded)) {
                continue;
            }

            $expectedData = \json_decode(
                \json_encode($event->toWire(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
                true,
                flags: \JSON_THROW_ON_ERROR,
            );

            new Expect()
                ->that($decoded['v'])->toBe(1)
                ->and($decoded['event'])->toBe(\array_search($event::class, $tags, true))
                ->and($decoded['data'])->toEqual($expectedData);
        }
    }

    #[Test]
    public function linesRoundTripBackToEventsThroughTheTagMapping(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new JsonLinesReporter($output));

        $classesByTag = JsonLinesReporter::tags();
        $events = CannedStream::events();

        foreach (\explode("\n", \rtrim($output->buffer(), "\n")) as $index => $line) {
            /** @var array{v: int, event: string, data: array<string, mixed>} $decoded */
            $decoded = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);

            $class = $classesByTag[$decoded['event']];
            $restored = $class::fromWire($decoded['data']);

            new Expect()
                ->that($restored::class)->toBe($events[$index]::class)
                ->and($restored->occurredAt)->toBe($events[$index]->occurredAt);
        }
    }

    #[Test]
    public function firstLineMatchesTheDocumentedEnvelopeShape(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new JsonLinesReporter($output));

        $lines = \explode("\n", $output->buffer());

        new Expect()->that($lines[0])->toBe(
            '{"v":1,"event":"run-started","data":{"runId":"run-1","plannedTests":6,"workers":2,"occurredAt":1750000000.5}}',
        );
    }

    #[Test]
    public function anUnmappedEventIsRejected(): void
    {
        $reporter = new JsonLinesReporter(new BufferOutput());

        $event = new class implements Event {
            public float $occurredAt = 1.0;

            #[\Override]
            public function toWire(): array
            {
                return ['occurredAt' => $this->occurredAt];
            }

            #[\Override]
            public static function fromWire(array $payload): static
            {
                throw new \LogicException('Not deserialisable.');
            }
        };

        new Expect()
            ->that(static fn() => $reporter->onEvent($event))
            ->toThrow(ReportingError::class, '/no stable tag/');
    }
}
