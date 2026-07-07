<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;

/**
 * Renders the run event stream into an output format. A reporter receives
 * every event in stream order (run, suite, class, test, and worker events)
 * and is told once, via finish(), that no further events will arrive.
 *
 * @internal
 */
interface Reporter
{
    public function onEvent(Event $event): void;

    /**
     * Called exactly once after the last event. Buffered reporters flush here.
     */
    public function finish(): void;
}
