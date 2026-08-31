<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Output\ExitCode;
use Greenlight\Expect\Expect;

final readonly class ExitCodeTest
{
    #[Test]
    public function namesCommandExitCodes(): void
    {
        Expect::that(ExitCode::SUCCESS)->toBe(0);
        Expect::that(ExitCode::FAILURE)->toBe(1);
        Expect::that(ExitCode::USAGE)->toBe(64);
    }
}
