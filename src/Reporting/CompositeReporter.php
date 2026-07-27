<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;

/**
 * Sends one event stream to multiple reporters.
 *
 * onEvent() and finish() invoke reporters in construction order. Thus, all
 * reporters receive events and the finish signal in the same order. tick()
 * invokes only reporters that implement Ticking.
 *
 * @internal
 */
final readonly class CompositeReporter implements Reporter, Ticking
{
    /**
     * @param list<Reporter> $reporters
     */
    public function __construct(private array $reporters) {}

    #[\Override]
    public function onEvent(Event $event): void
    {
        foreach ($this->reporters as $reporter) {
            $reporter->onEvent($event);
        }
    }

    #[\Override]
    public function tick(float $now): void
    {
        foreach ($this->reporters as $reporter) {
            if ($reporter instanceof Ticking) {
                $reporter->tick($now);
            }
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
