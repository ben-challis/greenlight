<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\Event;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Event\EventCodec;
use Greenlight\Internal\Event\EventCodecFailed;
use Greenlight\Reporting\JsonLinesReporter;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\TestId;
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
    public function aRetriedPassUsesTheExistingAttemptsField(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new JsonLinesReporter($output));

        $retried = \array_values(\array_filter(
            \explode("\n", \rtrim($output->buffer(), "\n")),
            static fn(string $line): bool => \str_contains($line, 'retriesFlakyEndpoint'),
        ));

        Expect::that($retried)
            ->because('JSONL MUST retain retry evidence without a schema change')
            ->toHaveCount(2);
        Expect::that($retried[1])
            ->toContain('"outcome":"passed"')
            ->toContain('"attempts":3');
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

    #[Test]
    public function aJsonEncodingFailureRemainsAReportGenerationFailure(): void
    {
        $resource = \fopen('php://memory', 'r');

        if ($resource === false) {
            throw new \RuntimeException('The test could not open a memory stream.');
        }

        $event = new TestFinished(new TestResult(
            new TestId(self::class, __FUNCTION__),
            Outcome::Errored,
            0.0,
            0,
            error: $this->unencodableThrowableDetail($resource),
        ), 1.0);
        $reporter = new JsonLinesReporter(new BufferOutput());

        try {
            Expect::that(static fn() => $reporter->onEvent($event))
                ->toThrow(static function (ReportGenerationFailed $failure): void {
                    Expect::that($failure->getMessage())
                        ->toBe('Greenlight could not encode the event as JSON.');
                    Expect::that($failure->getPrevious())
                        ->toBeInstanceOf(EventCodecFailed::class);
                });
        } finally {
            \fclose($resource);
        }
    }

    /** @param resource $resource */
    private function unencodableThrowableDetail(mixed $resource): ThrowableDetail
    {
        $reflection = new \ReflectionClass(ThrowableDetail::class);
        $detail = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'class' => \RuntimeException::class,
            'message' => 'Failed.',
            'file' => __FILE__,
            'line' => __LINE__,
            'stackFrames' => [$resource],
        ] as $property => $value) {
            $reflection->getProperty($property)->setValue($detail, $value);
        }

        return $detail;
    }
}
