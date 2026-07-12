<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Core\Event\Event;
use Greenlight\Reporting\Reporter;
use Greenlight\Runner\Worker\EventSink;

/**
 * Adapts the runner's event sink to a reporter.
 *
 * @internal
 */
final readonly class ReporterSink implements EventSink
{
    public function __construct(private Reporter $reporter) {}

    #[\Override]
    public function emit(Event $event): void
    {
        $this->reporter->onEvent($event);
    }
}
