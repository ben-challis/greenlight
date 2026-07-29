<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\CliError;
use Greenlight\Cli\CliOverrides;
use Greenlight\Cli\ParsedArguments;
use Greenlight\Expect\Expect;

final readonly class CliOverridesSingleShardRangeTest
{
    #[Test]
    public function invalidSingleShardIndexNamesTheOnlyValidIndex(): void
    {
        Expect::that(static fn(): CliOverrides => CliOverrides::fromArguments(
            new ParsedArguments(null, ['shard' => ['2/1']]),
        ))
            ->because('single-shard guidance MUST name its only valid index')
            ->toThrow(
                CliError::class,
                message: '--shard requires 1 <= n <= m. Received "2/1". Valid n values for 1 shard are 1 through 1.',
            );
    }
}
