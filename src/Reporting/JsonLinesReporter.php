<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\EventTags;
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

    public function __construct(
        private Output $output,
    ) {}

    /**
     * @return array<non-empty-string, class-string<Event>>
     */
    public static function tags(): array
    {
        return EventTags::all();
    }

    #[\Override]
    public function onEvent(Event $event): void
    {
        $tag = EventTags::tagFor($event);

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
