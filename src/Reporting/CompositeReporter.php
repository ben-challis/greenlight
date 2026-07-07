<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;

/**
 * Fans one event stream out to many reporters.
 *
 * onEvent() and finish() invoke the reporters in construction order, so every
 * reporter sees events and the finish signal in the same order.
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
