<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\CliError;
use Greenlight\Cli\CliOverrides;
use Greenlight\Cli\ParsedArguments;
use Greenlight\Expect\Expect;

final readonly class CliOverridesResourceLimitShapeTest
{
    #[Test]
    public function resourceLimitsRejectSurplusDelimiters(): void
    {
        $raw = 'postgres=1=surplus';

        Expect::that(static fn(): CliOverrides => CliOverrides::fromArguments(
            new ParsedArguments(null, ['resource-limit' => [$raw]]),
        ))
            ->because('a resource limit requires exactly one name-value delimiter')
            ->toThrow(
                CliError::class,
                message: '--resource-limit requires <name>=<limit>, such as postgres=2. '
                    . 'Received "postgres=1=surplus".',
            );
    }
}
