<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Doubles\Fake;
use Greenlight\Event\Event;
use Greenlight\Reporting\Reporter;

final class RecordingReporter implements Reporter, Fake
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
