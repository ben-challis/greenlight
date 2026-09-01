<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Internal\Process;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Process\GracefulShutdown;

final class GracefulShutdownTest
{
    #[Test]
    public function startsWithNothingRequested(): void
    {
        $shutdown = new GracefulShutdown();

        Expect::that($shutdown->requested())->because('starts with nothing requested')->toBeFalse();
        Expect::that($shutdown->signal())->because('starts with nothing requested')->toBe(null);
    }

    #[Test]
    public function keepsTheRequestedSignal(): void
    {
        $sigint = new GracefulShutdown();
        $sigint->request(2);

        Expect::that($sigint->requested())->because('has a requested signal')->toBeTrue();
        Expect::that($sigint->signal())->because('keeps the requested signal')->toBe(2);

        $sigterm = new GracefulShutdown();
        $sigterm->request(15);

        Expect::that($sigterm->signal())->because('keeps the requested signal')->toBe(15);
    }

    #[Test]
    public function keepsTheFirstSignal(): void
    {
        $shutdown = new GracefulShutdown();
        $shutdown->request(15);
        $shutdown->request(2);

        Expect::that($shutdown->signal())->because('keeps the first signal')->toBe(15);
    }
}
