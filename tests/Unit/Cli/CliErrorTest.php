<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\CliError;
use Greenlight\Expect\Expect;

final class CliErrorTest
{
    #[Test]
    public function optionErrorsGiveExactGuidance(): void
    {
        $actual = [
            CliError::unknownOption('--unknown')->getMessage(),
            CliError::bareDoubleDash()->getMessage(),
            CliError::optionTakesNoValue('watch')->getMessage(),
            CliError::optionRequiresValue('workers')->getMessage(),
            CliError::shortOptionRequiresValue('c', 'config')->getMessage(),
            CliError::unexpectedArgument('extra')->getMessage(),
            CliError::duplicateOption('workers')->getMessage(),
            CliError::emptyGroupName()->getMessage(),
            CliError::emptyFilterPattern()->getMessage(),
        ];

        Expect::that($actual)->toBe([
            'Unknown option "--unknown". Use greenlight --help to list options.',
            '"--" requires an option name.',
            'Option --watch does not take a value.',
            'Option --workers requires a value. Use --workers=<value>.',
            'Option -c requires a value. Use --config=<value>.',
            'Unexpected argument "extra".',
            'Specify option --workers only once.',
            '--group requires a group name.',
            '--filter requires a pattern.',
        ]);
    }

    #[Test]
    public function valueErrorsGiveExactGuidance(): void
    {
        $actual = [
            CliError::malformedShard('first')->getMessage(),
            CliError::shardOutOfRange('9/4', 4)->getMessage(),
            CliError::shardOutOfRange('1/0', 0)->getMessage(),
            CliError::invalidSeed('-1')->getMessage(),
            CliError::notAPositiveInteger('--workers', '0')->getMessage(),
            CliError::malformedResourceLimit('postgres')->getMessage(),
            CliError::duplicateResourceLimit('postgres')->getMessage(),
            CliError::unknownReporter('verbose')->getMessage(),
        ];

        Expect::that($actual)->toBe([
            '--shard requires <n>/<m>, such as 1/4. Received "first".',
            '--shard requires 1 <= n <= m. Received "9/4". Valid n values for 4 shards are 1 through 4.',
            '--shard requires 1 <= n <= m. Received "1/0".',
            '--seed requires a nonnegative integer. Received "-1".',
            '--workers requires a positive integer. Received "0".',
            '--resource-limit requires <name>=<limit>, such as postgres=2. Received "postgres".',
            'Set resource limit "postgres" only once.',
            'Unknown reporter "verbose". Select tty, plain, junit, jsonl, github, or teamcity.',
        ]);
    }
}
