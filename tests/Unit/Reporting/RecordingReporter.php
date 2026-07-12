<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Reporting\Reporter;

/**
 * Counts events and records finish() for composite fan-out assertions.
 */
final class RecordingReporter implements Reporter
{
    public int $eventCount = 0;

    public bool $finished = false;

    #[\Override]
    public function onEvent(Event $event): void
    {
        ++$this->eventCount;
    }

    #[\Override]
    public function finish(): void
    {
        $this->finished = true;
    }
}
