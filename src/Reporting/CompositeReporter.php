<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;

/**
 * Fans one event stream out to many reporters, preserving order. Reporters
 * are invoked in construction order for both events and finish().
 *
 * @internal
 */
final readonly class CompositeReporter implements Reporter
{
    /**
     * @param list<Reporter> $reporters
     */
    public function __construct(
        private array $reporters,
    ) {}

    #[\Override]
    public function onEvent(Event $event): void
    {
        foreach ($this->reporters as $reporter) {
            $reporter->onEvent($event);
        }
    }

    #[\Override]
    public function finish(): void
    {
        foreach ($this->reporters as $reporter) {
            $reporter->finish();
        }
    }
}
