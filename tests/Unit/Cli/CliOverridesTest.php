<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\CliError;
use Greenlight\Cli\CliOverrides;
use Greenlight\Cli\ParsedArguments;
use Greenlight\Config\WorkerCount;
use Greenlight\Expect\Expect;

final class CliOverridesTest
{
    #[Test]
    public function absentFlagsMeanNoOverrides(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments(null, []));

        Expect::that($overrides->workers)->toBe(null);
        Expect::that($overrides->stopAfterFailures)->toBe(null);
        Expect::that($overrides->groups)->toBe([]);
        Expect::that($overrides->seed)->toBe(null);
        Expect::that($overrides->excludeGroups)->toBe([]);
        Expect::that($overrides->excludeClasses)->toBe([]);
        Expect::that($overrides->excludeMethods)->toBe([]);
        Expect::that($overrides->excludePaths)->toBe([]);
        Expect::that($overrides->repeat)->toBe(null);
        Expect::that($overrides->repeatUntilFailure)->toBe(false);
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

        Expect::that($overrides->excludeGroups)->toBe(['slow', 'io']);
        Expect::that($overrides->excludeClasses)->toBe(['Alpha*']);
        Expect::that($overrides->excludeMethods)->toBe(['two', 'craw?s']);
        Expect::that($overrides->excludePaths)->toBe(['tests/Legacy']);
    }

    #[Test]
    public function extractsRepeatOptions(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments('run', ['repeat' => ['3']]));

        Expect::that($overrides->repeat)->toBe(3);
        Expect::that($overrides->repeatUntilFailure)->toBe(false);

        $overrides = CliOverrides::fromArguments(new ParsedArguments('run', ['repeat-until-failure' => [null]]));

        Expect::that($overrides->repeat)->toBe(null);
        Expect::that($overrides->repeatUntilFailure)->toBe(true);
    }

    #[Test]
    public function extractsTypedValues(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments('run', [
            'workers' => ['4'],
            'bail' => ['3'],
            'group' => ['slow', 'io'],
            'seed' => ['0'],
        ]));

        Expect::that($overrides->workers?->fixed)->toBe(4);
        Expect::that($overrides->stopAfterFailures)->toBe(3);
        Expect::that($overrides->groups)->toBe(['slow', 'io']);
        Expect::that($overrides->seed)->toBe(0);
    }

    #[Test]
    public function bailWithoutAValueMeansStopAfterTheFirstFailure(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments(null, ['bail' => [null]]));

        Expect::that($overrides->stopAfterFailures)->toBe(1);
    }

    #[Test]
    public function workersAutoIsKeptAsTheAutoMarker(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments(null, ['workers' => ['auto']]));

        Expect::that($overrides->workers instanceof WorkerCount && $overrides->workers->isAuto())->toBeTrue();
    }

    #[Test]
    public function shardOutOfRangeNamesTheValidRange(): void
    {
        try {
            CliOverrides::fromArguments(new ParsedArguments(null, ['shard' => ['632/13']]));
        } catch (CliError $error) {
            Expect::that($error->getMessage())->toBe(
                '--shard needs 1 <= n <= m, got "632/13". With 13 shards, n must be between 1 and 13.',
            );

            return;
        }

        throw new \RuntimeException('Expected an out-of-range shard to be rejected.');
    }

    #[Test]
    public function shardWithZeroShardsOmitsTheRangeHint(): void
    {
        try {
            CliOverrides::fromArguments(new ParsedArguments(null, ['shard' => ['1/0']]));
        } catch (CliError $error) {
            Expect::that($error->getMessage())->toBe('--shard needs 1 <= n <= m, got "1/0".');

            return;
        }

        throw new \RuntimeException('Expected a zero-shard spec to be rejected.');
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
            'seed word' => ['seed' => ['tomorrow']],
            'negative seed' => ['seed' => ['-1']],
            'empty exclude group' => ['exclude-group' => ['']],
            'empty exclude class' => ['exclude-class' => ['']],
            'empty exclude method' => ['exclude-method' => ['']],
            'empty exclude path' => ['exclude-path' => ['']],
            'repeat zero' => ['repeat' => ['0']],
            'repeat word' => ['repeat' => ['abc']],
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
