<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\CliError;
use Greenlight\Expect\Expect;

final class CliErrorTest
{
    /**
     * @param \Closure(): CliError $create
     */
    #[Test]
    #[DataSet('errors')]
    public function errorsGiveExactGuidance(\Closure $create, string $message): void
    {
        Expect::that($create()->getMessage())
            ->because('each CLI error MUST give exact guidance')
            ->toBe($message);
    }

    /**
     * @return iterable<string, array{\Closure(): CliError, non-empty-string}>
     */
    public static function errors(): iterable
    {
        yield 'unknown option' => [
            static fn(): CliError => CliError::unknownOption('--unknown'),
            'Unknown option "--unknown". Use greenlight --help to list options.',
        ];
        yield 'bare double dash' => [
            CliError::bareDoubleDash(...),
            '"--" requires an option name.',
        ];
        yield 'option takes no value' => [
            static fn(): CliError => CliError::optionTakesNoValue('watch'),
            'Option --watch does not take a value.',
        ];
        yield 'option requires value' => [
            static fn(): CliError => CliError::optionRequiresValue('workers'),
            'Option --workers requires a value. Use --workers=<value>.',
        ];
        yield 'short option requires value' => [
            static fn(): CliError => CliError::shortOptionRequiresValue('c', 'config'),
            'Option -c requires a value. Use --config=<value>.',
        ];
        yield 'unexpected argument' => [
            static fn(): CliError => CliError::unexpectedArgument('extra'),
            'Unexpected argument "extra".',
        ];
        yield 'duplicate option' => [
            static fn(): CliError => CliError::duplicateOption('workers'),
            'Specify option --workers only once.',
        ];
        yield 'empty group name' => [
            CliError::emptyGroupName(...),
            '--group requires a group name.',
        ];
        yield 'empty filter pattern' => [
            CliError::emptyFilterPattern(...),
            '--filter requires a pattern.',
        ];
        yield 'malformed shard' => [
            static fn(): CliError => CliError::malformedShard('first'),
            '--shard requires <n>/<m>, such as 1/4. Received "first".',
        ];
        yield 'shard index out of range' => [
            static fn(): CliError => CliError::shardOutOfRange('9/4', 4),
            '--shard requires 1 <= n <= m. Received "9/4". Valid n values for 4 shards are 1 through 4.',
        ];
        yield 'shard count out of range' => [
            static fn(): CliError => CliError::shardOutOfRange('1/0', 0),
            '--shard requires 1 <= n <= m. Received "1/0".',
        ];
        yield 'invalid seed' => [
            static fn(): CliError => CliError::invalidSeed('-1'),
            '--seed requires a nonnegative integer. Received "-1".',
        ];
        yield 'nonpositive integer' => [
            static fn(): CliError => CliError::notAPositiveInteger('--workers', '0'),
            '--workers requires a positive integer. Received "0".',
        ];
        yield 'malformed resource limit' => [
            static fn(): CliError => CliError::malformedResourceLimit('postgres'),
            '--resource-limit requires <name>=<limit>, such as postgres=2. Received "postgres".',
        ];
        yield 'duplicate resource limit' => [
            static fn(): CliError => CliError::duplicateResourceLimit('postgres'),
            'Set resource limit "postgres" only once.',
        ];
        yield 'unknown reporter' => [
            static fn(): CliError => CliError::unknownReporter('verbose', ['plain', 'custom']),
            'Unknown reporter "verbose". Select one of: plain, custom.',
        ];
    }
}
