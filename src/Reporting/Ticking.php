<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * Opt-in for reporters that render a live display and want wall-clock
 * updates between events.
 *
 * @internal
 */
interface Ticking
{
    public function tick(float $now): void;
}
