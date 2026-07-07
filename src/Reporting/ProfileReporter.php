<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Reporting\Output\Output;

/**
 * Renders the run profile after the main reporter's summary. Added to the
 * reporter set by --profile; the aggregation itself is reusable offline via
 * ProfileAggregator over a saved jsonl stream.
 *
 * @internal
 */
final readonly class ProfileReporter implements Reporter
{
    private ProfileAggregator $aggregator;

    public function __construct(
        private Output $output,
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
        $this->output->write($this->aggregator->render());
    }
}
