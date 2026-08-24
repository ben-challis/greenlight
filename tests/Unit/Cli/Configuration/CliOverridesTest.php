<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Configuration;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Configuration\CliOverrides;
use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Config\WorkerCount;
use Greenlight\Expect\Expect;

final class CliOverridesTest
{
    #[Test]
    public function absentFlagsMeanNoOverrides(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments(null, []));

        Expect::that($overrides->execution->workers)->because('absent flags mean no overrides')->toBe(null);
        Expect::that($overrides->execution->stopAfterFailures)->because('absent flags mean no overrides')->toBe(null);
        Expect::that($overrides->selection->include->groups)->because('absent flags mean no overrides')->toBe([]);
        Expect::that($overrides->seed)->because('absent flags mean no overrides')->toBe(null);
        Expect::that($overrides->selection->include->exactIds)->because('absent flags mean no overrides')->toBe([]);
        Expect::that($overrides->selection->exclude->groups)->because('absent flags mean no overrides')->toBe([]);
        Expect::that($overrides->selection->exclude->classes)->because('absent flags mean no overrides')->toBe([]);
        Expect::that($overrides->selection->exclude->methods)->because('absent flags mean no overrides')->toBe([]);
        Expect::that($overrides->selection->exclude->paths)->because('absent flags mean no overrides')->toBe([]);
        Expect::that($overrides->repeat->count)->because('absent flags mean no overrides')->toBe(null);
        Expect::that($overrides->repeat->untilFailure)->because('absent flags mean no overrides')->toBe(false);
        Expect::that($overrides->execution->artifactsDirectory)->because('absent flags mean no overrides')->toBe(null);
        Expect::that($overrides->execution->resourceLimits)->because('absent flags mean no overrides')->toBe([]);
        Expect::that($overrides->coverage->includePaths)->because('absent flags mean no overrides')->toBe([]);
        Expect::that($overrides->coverage->perTestTarget)->because('absent flags mean no overrides')->toBe(null);
        Expect::that($overrides->coverage->disabled)->because('absent flags mean no overrides')->toBeFalse();
        Expect::that($overrides->suiteNames)->because('absent flags mean no overrides')->toBe([]);
        Expect::that($overrides->suiteTags)->because('absent flags mean no overrides')->toBe([]);
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

        Expect::that($overrides->selection->exclude->groups)->because('extracts exclusion lists')->toBe(['slow', 'io']);
        Expect::that($overrides->selection->exclude->classes)->because('extracts exclusion lists')->toBe(['Alpha*']);
        Expect::that($overrides->selection->exclude->methods)->because('extracts exclusion lists')->toBe(['two', 'craw?s']);
        Expect::that($overrides->selection->exclude->paths)->because('extracts exclusion lists')->toBe(['tests/Legacy']);
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

        Expect::that($overrides->selection->include->groups)
            ->because('a zero string MUST remain a group selection')
            ->toBe(['0']);
        Expect::that($overrides->selection->include->idPatterns)
            ->because('a zero string MUST remain a filter selection')
            ->toBe(['0']);
        Expect::that($overrides->selection->exclude->groups)
            ->because('a zero string MUST remain an excluded group selection')
            ->toBe(['0']);
        Expect::that($overrides->selection->exclude->classes)
            ->because('a zero string MUST remain an excluded class selection')
            ->toBe(['0']);
        Expect::that($overrides->selection->exclude->methods)
            ->because('a zero string MUST remain an excluded method selection')
            ->toBe(['0']);
        Expect::that($overrides->selection->exclude->paths)
            ->because('a zero string MUST remain an excluded path selection')
            ->toBe(['0']);
    }

    #[Test]
    public function extractsRepeatOptions(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments('run', ['repeat' => ['3']]));

        Expect::that($overrides->repeat->count)->because('extracts repeat options')->toBe(3);
        Expect::that($overrides->repeat->untilFailure)->because('extracts repeat options')->toBe(false);

        $overrides = CliOverrides::fromArguments(new ParsedArguments('run', ['repeat-until-failure' => [null]]));

        Expect::that($overrides->repeat->count)->because('extracts repeat options')->toBe(null);
        Expect::that($overrides->repeat->untilFailure)->because('extracts repeat options')->toBe(true);
    }

    #[Test]
    public function extractsTypedValues(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments('run', [
            'workers' => ['4'],
            'bail' => ['3'],
            'group' => ['slow', 'io'],
            'suite' => ['unit', 'acceptance'],
            'suite-tag' => ['fast', 'io'],
            'seed' => ['0'],
            'test-id' => ['App\ExampleTest::one', 'App\ExampleTest::two'],
            'artifacts-dir' => ['build/evidence'],
            'resource-limit' => ['postgres=3', 'payments-sandbox=1', 'cache.primary_1=2'],
            'minimum-coverage' => ['95.25'],
            'maximum-uncovered-lines' => ['0'],
            'branch-coverage' => [null],
            'minimum-branch-coverage' => ['75.5'],
            'maximum-uncovered-branches' => ['1'],
            'require-coverage-driver' => [null],
            'coverage-include' => ['src', 'packages/core'],
            'coverage-map' => ['build/test-coverage.jsonl'],
        ]));

        Expect::that($overrides->execution->workers?->fixed)->because('extracts typed values')->toBe(4);
        Expect::that($overrides->execution->stopAfterFailures)->because('extracts typed values')->toBe(3);
        Expect::that($overrides->selection->include->groups)->because('extracts typed values')->toBe(['slow', 'io']);
        Expect::that($overrides->suiteNames)->because('extracts typed values')->toBe(['unit', 'acceptance']);
        Expect::that($overrides->suiteTags)->because('extracts typed values')->toBe(['fast', 'io']);
        Expect::that($overrides->seed)->because('extracts typed values')->toBe(0);
        Expect::that($overrides->selection->include->exactIds)->because('extracts typed values')->toBe(['App\ExampleTest::one', 'App\ExampleTest::two']);
        Expect::that($overrides->execution->artifactsDirectory)->because('extracts typed values')->toBe('build/evidence');
        Expect::that($overrides->execution->resourceLimits)->because('extracts typed values')->toBe([
            'postgres' => 3,
            'payments-sandbox' => 1,
            'cache.primary_1' => 2,
        ]);
        Expect::that($overrides->coverage->minimumPercentage)->because('extracts typed values')->toBe(95.25);
        Expect::that($overrides->coverage->maximumUncoveredLines)->because('extracts typed values')->toBe(0);
        Expect::that($overrides->coverage->requireDriver)->because('extracts typed values')->toBeTrue();
        Expect::that($overrides->coverage->branchCoverage)->toBeTrue();
        Expect::that($overrides->coverage->minimumBranchPercentage)->toBe(75.5);
        Expect::that($overrides->coverage->maximumUncoveredBranches)->toBe(1);
        Expect::that($overrides->coverage->includePaths)->toBe(['src', 'packages/core']);
        Expect::that($overrides->coverage->perTestTarget)->toBe('build/test-coverage.jsonl');
        Expect::that($overrides->coverage->disabled)->toBeFalse();
    }

    #[Test]
    #[DataSet('invalidCoverageOverrides')]
    public function rejectsInvalidCoverageOverrides(string $option, string $raw, string $message): void
    {
        Expect::that(static fn(): CliOverrides => CliOverrides::fromArguments(
            new ParsedArguments('run', [$option => [$raw]]),
        ))
            ->because('coverage CLI gates MUST reject values outside their public boundaries')
            ->toThrow(CliError::class, message: $message);
    }

    /** @return iterable<string, array{non-empty-string, string, non-empty-string}> */
    public static function invalidCoverageOverrides(): iterable
    {
        yield 'percentage above 100' => [
            'minimum-coverage',
            '100.01',
            '--minimum-coverage requires a percentage from 0 through 100 with at most two decimal places. Received "100.01".',
        ];
        yield 'percentage with excess precision' => [
            'minimum-coverage',
            '99.999',
            '--minimum-coverage requires a percentage from 0 through 100 with at most two decimal places. Received "99.999".',
        ];
        yield 'negative uncovered lines' => [
            'maximum-uncovered-lines',
            '-1',
            '--maximum-uncovered-lines requires a nonnegative integer. Received "-1".',
        ];
        yield 'branch percentage above 100' => [
            'minimum-branch-coverage',
            '100.01',
            '--minimum-branch-coverage requires a percentage from 0 through 100 with at most two decimal places. Received "100.01".',
        ];
        yield 'negative uncovered branches' => [
            'maximum-uncovered-branches',
            '-1',
            '--maximum-uncovered-branches requires a nonnegative integer. Received "-1".',
        ];
    }

    #[Test]
    public function noCoverageRejectsCommandLineCoverageSettings(): void
    {
        Expect::that(static fn(): CliOverrides => CliOverrides::fromArguments(new ParsedArguments('run', [
            'no-coverage' => [null],
            'coverage-map' => ['coverage.jsonl'],
        ])))->toThrow(
            CliError::class,
            message: '--no-coverage cannot be combined with options that enable or require coverage.',
        );

        Expect::that(static fn(): CliOverrides => CliOverrides::fromArguments(new ParsedArguments('run', [
            'no-coverage' => [null],
            'minimum-coverage' => ['90'],
        ])))->toThrow(
            CliError::class,
            message: '--no-coverage cannot be combined with options that enable or require coverage.',
        );
    }

    #[Test]
    public function bailWithoutAValueMeansStopAfterTheFirstFailure(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments(null, ['bail' => [null]]));

        Expect::that($overrides->execution->stopAfterFailures)->because('bail without a value means stop after the first failure')->toBe(1);
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

        Expect::that($overrides->execution->workers)->because('workers auto is kept as the auto marker')->toBeInstanceOf(WorkerCount::class);
        Expect::that($overrides->execution->workers->isAuto())->because('workers auto is kept as the auto marker')->toBeTrue();
    }

    #[Test]
    public function validShardKeepsIndexAndCountOrder(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments(null, ['shard' => ['2/3']]));

        Expect::that($overrides->selection->shard)
            ->because('--shard MUST keep the selected index before the total count')
            ->toBe([2, 3]);
    }

    #[Test]
    #[DataSet('invalidShards')]
    public function rejectsInvalidShardWithExactGuidance(string $raw, string $message): void
    {
        Expect::that(static fn(): CliOverrides => CliOverrides::fromArguments(
            new ParsedArguments(null, ['shard' => [$raw]]),
        ))
            ->because('--shard MUST reject malformed and out-of-range values')
            ->toThrow(CliError::class, message: $message);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function invalidShards(): iterable
    {
        yield 'trailing newline' => [
            "1/2\n",
            "--shard requires <n>/<m>, such as 1/4. Received \"1/2\n\".",
        ];

        yield 'index above shard count' => [
            '632/13',
            '--shard requires 1 <= n <= m. Received "632/13". Valid n values for 13 shards are 1 through 13.',
        ];

        yield 'zero shard count' => [
            '1/0',
            '--shard requires 1 <= n <= m. Received "1/0".',
        ];
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
