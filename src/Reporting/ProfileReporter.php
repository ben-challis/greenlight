<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Reporting\Output\Output;

/**
 * Writes the run profile after the primary reporter summary.
 *
 * --profile adds this reporter. ProfileAggregator can also process a saved
 * JSONL stream.
 *
 * @internal
 */
final readonly class ProfileReporter implements Reporter
{
    private ProfileAggregator $aggregator;

    public function __construct(
        private Output $output,
        private Style $style = new Style(ansi: false),
    ) {
        $this->aggregator = new ProfileAggregator();
    }

    #[\Override]
    public function onEvent(Event $event): void
    {
        $this->aggregator->onEvent($event);
    }

    #[\Override]
    public function finish(): void
    {
        $this->output->write($this->aggregator->render($this->style));
    }
}
