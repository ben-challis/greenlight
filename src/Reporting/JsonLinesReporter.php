<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\SuiteFinished;
use Greenlight\Core\Event\SuiteStarted;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\TestStarted;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Reporting\Output\Output;

/**
 * Streams one JSON object per event as it arrives, each shaped as
 * {"v": 1, "event": "<tag>", "data": {...}} where data is the event's wire
 * payload. Tags are stable and additive only; the schema is documented in
 * docs/architecture/jsonl.md.
 *
 * @internal
 */
final readonly class JsonLinesReporter implements Reporter
{
    private const int VERSION = 1;

    /**
     * @var array<class-string<Event>, non-empty-string>
     */
    private const array TAGS = [
        RunStarted::class => 'run-started',
        RunFinished::class => 'run-finished',
        SuiteStarted::class => 'suite-started',
        SuiteFinished::class => 'suite-finished',
        TestClassStarted::class => 'class-started',
        TestClassFinished::class => 'class-finished',
        TestStarted::class => 'test-started',
        TestFinished::class => 'test-finished',
        WorkerSpawned::class => 'worker-spawned',
        WorkerRecycled::class => 'worker-recycled',
    ];

    public function __construct(
        private Output $output,
    ) {}

    /**
     * @return array<class-string<Event>, non-empty-string>
     */
    public static function tags(): array
    {
        return self::TAGS;
    }

    #[\Override]
    public function onEvent(Event $event): void
    {
        $tag = self::TAGS[$event::class] ?? null;

        if ($tag === null) {
            throw ReportingError::unmappedEvent($event::class);
        }

        $line = \json_encode(
            ['v' => self::VERSION, 'event' => $tag, 'data' => $event->toWire()],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE,
        );

        $this->output->write($line . "\n");
    }

    #[\Override]
    public function finish(): void {}
}
