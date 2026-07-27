<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;

/**
 * Converts the run event stream to an output format.
 *
 * A reporter receives each event in stream order. The stream contains run,
 * suite, test-class, test, and worker events. Greenlight calls `finish()` one
 * time after the final event.
 */
interface Reporter
{
    /**
     * @throws ReportingError when the event cannot be rendered or delivered
     */
    public function onEvent(Event $event): void;

    /**
     * Greenlight calls this method exactly one time after the final event.
     * Reporters with buffers write their buffered text here.
     *
     * @throws ReportingError when the output cannot be rendered or delivered
     */
    public function finish(): void;
}
