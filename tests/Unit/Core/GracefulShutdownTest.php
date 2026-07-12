<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\GracefulShutdown;
use Greenlight\Expect\Expect;

final class GracefulShutdownTest
{
    #[Test]
    public function startsWithNothingRequested(): void
    {
        $shutdown = new GracefulShutdown();

        Expect::that($shutdown->requested())->toBeFalse();
        Expect::that($shutdown->exitCode())->toBe(null);
    }

    #[Test]
    public function mapsSignalsToConventionalExitCodes(): void
    {
        $sigint = new GracefulShutdown();
        $sigint->request(2);

        Expect::that($sigint->requested())->toBeTrue();
        Expect::that($sigint->exitCode())->toBe(130);

        $sigterm = new GracefulShutdown();
        $sigterm->request(15);

        Expect::that($sigterm->exitCode())->toBe(143);
    }

    #[Test]
    public function keepsTheFirstSignal(): void
    {
        $shutdown = new GracefulShutdown();
        $shutdown->request(15);
        $shutdown->request(2);

        Expect::that($shutdown->exitCode())->toBe(143);
    }
}
