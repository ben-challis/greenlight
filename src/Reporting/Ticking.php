<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * Lets a reporter request wall-clock updates between events for a live display.
 *
 * @internal
 */
interface Ticking
{
    /**
     * @param float $now epoch seconds from microtime(true), the same clock as Event::occurredAt
     *
     * @throws ReportingError when the update cannot be rendered or delivered
     */
    public function tick(float $now): void;
}
