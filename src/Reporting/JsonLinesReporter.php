<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Event\Event;
use Greenlight\Internal\Event\EventCodec;
use Greenlight\Internal\Event\EventCodecFailed;
use Greenlight\Internal\Event\EventCodecFailureKind;

/**
 * Writes one JSON object for each event when the event arrives.
 *
 * Each line has the form {"v": 3, "event": "<tag>", "data": {...}}. data is
 * the event wire payload.
 *
 * The schema is in docs/architecture/jsonl.md.
 *
 * @internal
 */
final readonly class JsonLinesReporter implements Reporter
{
    public function __construct(private Output $output) {}

    /**
     * @throws ReportGenerationFailed
     */
    #[\Override]
    public function onEvent(Event $event): void
    {
        try {
            $line = EventCodec::encodeJsonLine($event);
        } catch (EventCodecFailed $failure) {
            if ($failure->kind === EventCodecFailureKind::UnmappedEvent) {
                throw ReportGenerationFailed::unmappedEvent($event::class);
            }

            throw ReportGenerationFailed::eventEncodingFailed($failure);
        }

        $this->output->write($line);
    }

    #[\Override]
    public function finish(): void {}
}
