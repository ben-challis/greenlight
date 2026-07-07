<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\GracefulShutdown;
use Greenlight\Tests\Support\Check;

final class GracefulShutdownTest
{
    #[Test]
    public function startsWithNothingRequested(): void
    {
        $shutdown = new GracefulShutdown();

        Check::true(!$shutdown->requested(), 'no shutdown requested initially');
        Check::same(null, $shutdown->exitCode(), 'exit code before any signal');
    }

    #[Test]
    public function mapsSignalsToConventionalExitCodes(): void
    {
        $sigint = new GracefulShutdown();
        $sigint->request(2);

        Check::true($sigint->requested(), 'requested after SIGINT');
        Check::same(130, $sigint->exitCode(), 'SIGINT exit code');

        $sigterm = new GracefulShutdown();
        $sigterm->request(15);

        Check::same(143, $sigterm->exitCode(), 'SIGTERM exit code');
    }

    #[Test]
    public function keepsTheFirstSignal(): void
    {
        $shutdown = new GracefulShutdown();
        $shutdown->request(15);
        $shutdown->request(2);

        Check::same(143, $shutdown->exitCode(), 'exit code reflects the first signal');
    }
}
