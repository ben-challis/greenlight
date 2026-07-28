<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Doubles\Fake;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\Ticking;

final class RecordingTickingReporter implements Reporter, Ticking, Fake
{
    /**
     * @var list<float>
     */
    public array $ticks = [];

    #[\Override]
    public function onEvent(Event $event): void {}

    #[\Override]
    public function tick(float $now): void
    {
        $this->ticks[] = $now;
    }

    #[\Override]
    public function finish(): void {}
}
