<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\CliError;
use Greenlight\Cli\CliOverrides;
use Greenlight\Cli\ParsedArguments;
use Greenlight\Config\WorkerCount;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;

final class CliOverridesTest
{
    #[Test]
    public function absentFlagsMeanNoOverrides(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments(null, []));

        Expect::that($overrides->workers)->because('absent flags mean no overrides')->toBe(null);
        Expect::that($overrides->stopAfterFailures)->because('absent flags mean no overrides')->toBe(null);
        Expect::that($overrides->groups)->because('absent flags mean no overrides')->toBe([]);
        Expect::that($overrides->seed)->because('absent flags mean no overrides')->toBe(null);
        Expect::that($overrides->testIds)->because('absent flags mean no overrides')->toBe([]);
        Expect::that($overrides->excludeGroups)->because('absent flags mean no overrides')->toBe([]);
        Expect::that($overrides->excludeClasses)->because('absent flags mean no overrides')->toBe([]);
        Expect::that($overrides->excludeMethods)->because('absent flags mean no overrides')->toBe([]);
        Expect::that($overrides->excludePaths)->because('absent flags mean no overrides')->toBe([]);
        Expect::that($overrides->repeat)->because('absent flags mean no overrides')->toBe(null);
        Expect::that($overrides->repeatUntilFailure)->because('absent flags mean no overrides')->toBe(false);
        Expect::that($overrides->artifactsDirectory)->because('absent flags mean no overrides')->toBe(null);
        Expect::that($overrides->resourceLimits)->because('absent flags mean no overrides')->toBe([]);
    }

    #[Test]
    public function extractsExclusionLists(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments('run', [
            'exclude-group' => ['slow', 'io'],
            'exclude-class' => ['Alpha*'],
            'exclude-method' => ['two', 'craw?s'],
            'exclude-path' => ['tests/Legacy'],
        ]));

        Expect::that($overrides->excludeGroups)->because('extracts exclusion lists')->toBe(['slow', 'io']);
        Expect::that($overrides->excludeClasses)->because('extracts exclusion lists')->toBe(['Alpha*']);
        Expect::that($overrides->excludeMethods)->because('extracts exclusion lists')->toBe(['two', 'craw?s']);
        Expect::that($overrides->excludePaths)->because('extracts exclusion lists')->toBe(['tests/Legacy']);
    }

    #[Test]
    public function zeroStringsRemainSelectionValues(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments('run', [
            'group' => ['0'],
            'filter' => ['0'],
            'exclude-group' => ['0'],
            'exclude-class' => ['0'],
            'exclude-method' => ['0'],
            'exclude-path' => ['0'],
        ]));

        Expect::that($overrides->groups)
            ->because('a zero string MUST remain a group selection')
            ->toBe(['0'])
            ->and($overrides->filters)
            ->because('a zero string MUST remain a filter selection')
            ->toBe(['0'])
            ->and($overrides->excludeGroups)
            ->because('a zero string MUST remain an excluded group selection')
            ->toBe(['0'])
            ->and($overrides->excludeClasses)
            ->because('a zero string MUST remain an excluded class selection')
            ->toBe(['0'])
            ->and($overrides->excludeMethods)
            ->because('a zero string MUST remain an excluded method selection')
            ->toBe(['0'])
            ->and($overrides->excludePaths)
            ->because('a zero string MUST remain an excluded path selection')
            ->toBe(['0']);
    }

    #[Test]
    public function extractsRepeatOptions(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments('run', ['repeat' => ['3']]));

        Expect::that($overrides->repeat)->because('extracts repeat options')->toBe(3);
        Expect::that($overrides->repeatUntilFailure)->because('extracts repeat options')->toBe(false);

        $overrides = CliOverrides::fromArguments(new ParsedArguments('run', ['repeat-until-failure' => [null]]));

        Expect::that($overrides->repeat)->because('extracts repeat options')->toBe(null);
        Expect::that($overrides->repeatUntilFailure)->because('extracts repeat options')->toBe(true);
    }

    #[Test]
    public function extractsTypedValues(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments('run', [
            'workers' => ['4'],
            'bail' => ['3'],
            'group' => ['slow', 'io'],
            'seed' => ['0'],
            'test-id' => ['App\ExampleTest::one', 'App\ExampleTest::two'],
            'artifacts-dir' => ['build/evidence'],
            'resource-limit' => ['postgres=3', 'payments-sandbox=1', 'cache.primary_1=2'],
        ]));

        Expect::that($overrides->workers?->fixed)->because('extracts typed values')->toBe(4);
        Expect::that($overrides->stopAfterFailures)->because('extracts typed values')->toBe(3);
        Expect::that($overrides->groups)->because('extracts typed values')->toBe(['slow', 'io']);
        Expect::that($overrides->seed)->because('extracts typed values')->toBe(0);
        Expect::that($overrides->testIds)->because('extracts typed values')->toBe(['App\ExampleTest::one', 'App\ExampleTest::two']);
        Expect::that($overrides->artifactsDirectory)->because('extracts typed values')->toBe('build/evidence');
        Expect::that($overrides->resourceLimits)->because('extracts typed values')->toBe([
            'postgres' => 3,
            'payments-sandbox' => 1,
            'cache.primary_1' => 2,
        ]);
    }

    #[Test]
    public function bailWithoutAValueMeansStopAfterTheFirstFailure(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments(null, ['bail' => [null]]));

        Expect::that($overrides->stopAfterFailures)->because('bail without a value means stop after the first failure')->toBe(1);
    }

    #[Test]
    public function emptyFilterPatternsAreRejectedExactly(): void
    {
        Expect::that(static function (): void {
            CliOverrides::fromArguments(new ParsedArguments(null, ['filter' => ['']]));
        })
            ->because('an empty filter cannot select tests')
            ->toThrow(CliError::class, message: '--filter requires a pattern.');
    }

    #[Test]
    public function workersAutoIsKeptAsTheAutoMarker(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments(null, ['workers' => ['auto']]));

        Expect::that($overrides->workers)->because('workers auto is kept as the auto marker')->toBeInstanceOf(WorkerCount::class);
        \assert($overrides->workers instanceof WorkerCount);
        Expect::that($overrides->workers->isAuto())->because('workers auto is kept as the auto marker')->toBeTrue();
    }

    #[Test]
    public function validShardKeepsIndexAndCountOrder(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments(null, ['shard' => ['2/3']]));

        Expect::that($overrides->shard)
            ->because('--shard MUST keep the selected index before the total count')
            ->toBe([2, 3]);
    }

    #[Test]
    public function shardOutOfRangeNamesTheValidRange(): void
    {
        try {
            CliOverrides::fromArguments(new ParsedArguments(null, ['shard' => ['632/13']]));
        } catch (CliError $error) {
            Expect::that($error->getMessage())->toBe(
                '--shard requires 1 <= n <= m. Received "632/13". Valid n values for 13 shards are 1 through 13.',
            );

            return;
        }

        Fail::because('Expected CliOverrides::fromArguments() to reject out-of-range shard "632/13".');
    }

    #[Test]
    public function shardWithZeroShardsOmitsTheRangeHint(): void
    {
        try {
            CliOverrides::fromArguments(new ParsedArguments(null, ['shard' => ['1/0']]));
        } catch (CliError $error) {
            Expect::that($error->getMessage())->toBe('--shard requires 1 <= n <= m. Received "1/0".');

            return;
        }

        Fail::because('Expected CliOverrides::fromArguments() to reject zero-shard specification "1/0".');
    }

    /**
     * @param array<string, list<string|null>> $options
     */
    #[Test]
    #[DataSet('integerOverflows')]
    public function rejectsIntegerValuesOutsideThePlatformRange(array $options, string $message): void
    {
        Expect::that(static fn(): CliOverrides => CliOverrides::fromArguments(
            new ParsedArguments(null, $options),
        ))
            ->because('CLI integers MUST fit the platform integer range')
            ->toThrow(CliError::class, message: $message);
    }

    /**
     * @return iterable<string, array{array<string, list<string|null>>, non-empty-string}>
     */
    public static function integerOverflows(): iterable
    {
        $overflow = \PHP_INT_MAX . '0';

        yield 'workers' => [
            ['workers' => [$overflow]],
            \sprintf('--workers requires a positive integer. Received "%s".', $overflow),
        ];

        yield 'bail' => [
            ['bail' => [$overflow]],
            \sprintf('--bail requires a positive integer. Received "%s".', $overflow),
        ];

        yield 'repeat' => [
            ['repeat' => [$overflow]],
            \sprintf('--repeat requires a positive integer. Received "%s".', $overflow),
        ];

        yield 'resource limit' => [
            ['resource-limit' => ['postgres=' . $overflow]],
            \sprintf('--resource-limit requires a positive integer. Received "%s".', $overflow),
        ];

        yield 'seed' => [
            ['seed' => [$overflow]],
            \sprintf('--seed requires a nonnegative integer. Received "%s".', $overflow),
        ];

        yield 'shard index' => [
            ['shard' => [$overflow . '/4']],
            \sprintf(
                '--shard requires 1 <= n <= m. Received "%s/4". Valid n values for 4 shards are 1 through 4.',
                $overflow,
            ),
        ];

        yield 'shard count' => [
            ['shard' => ['1/' . $overflow]],
            \sprintf('--shard requires 1 <= n <= m. Received "1/%s".', $overflow),
        ];
    }

    #[Test]
    public function rejectsUnusableValues(): void
    {
        $unusable = [
            'workers zero' => ['workers' => ['0']],
            'workers word' => ['workers' => ['many']],
            'bail zero' => ['bail' => ['0']],
            'bail word' => ['bail' => ['soon']],
            'empty group' => ['group' => ['']],
            'empty test id' => ['test-id' => ['']],
            'seed word' => ['seed' => ['tomorrow']],
            'negative seed' => ['seed' => ['-1']],
            'empty exclude group' => ['exclude-group' => ['']],
            'empty exclude class' => ['exclude-class' => ['']],
            'empty exclude method' => ['exclude-method' => ['']],
            'empty exclude path' => ['exclude-path' => ['']],
            'repeat zero' => ['repeat' => ['0']],
            'repeat word' => ['repeat' => ['abc']],
            'empty artifacts directory' => ['artifacts-dir' => ['']],
            'malformed resource limit' => ['resource-limit' => ['postgres']],
            'zero resource limit' => ['resource-limit' => ['postgres=0']],
            'invalid resource name' => ['resource-limit' => ['Postgres=2']],
            'duplicate resource limit' => ['resource-limit' => ['postgres=1', 'postgres=2']],
        ];

        foreach ($unusable as $options) {
            Expect::that(
                static function () use ($options): void {
                    CliOverrides::fromArguments(new ParsedArguments(null, $options));
                },
            )->toThrow(CliError::class);
        }
    }
}
