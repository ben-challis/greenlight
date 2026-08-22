<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Event\Event;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Runner\Worker\EventSink;

/** @internal */
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
