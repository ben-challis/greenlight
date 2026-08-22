<?php

declare(strict_types=1);

namespace Greenlight\Cli\Reporting;

use Greenlight\Event\Event;
use Greenlight\Event\EventSink;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\ReportGenerationFailed;

/**
 * Adapts a reporter to the event sink seam.
 *
 * @internal
 */
final readonly class ReporterSink implements EventSink
{
    public function __construct(private Reporter $reporter) {}

    /**
     * @throws ReportGenerationFailed
     */
    #[\Override]
    public function emit(Event $event): void
    {
        $this->reporter->onEvent($event);
    }
}
