<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\CliError;
use Greenlight\Cli\CliOverrides;
use Greenlight\Cli\ParsedArguments;
use Greenlight\Config\WorkerCount;
use Greenlight\Tests\Support\Check;

final class CliOverridesTest
{
    #[Test]
    public function absentFlagsMeanNoOverrides(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments(null, []));

        Check::same(null, $overrides->workers, 'workers');
        Check::same(null, $overrides->stopAfterFailures, 'stopAfterFailures');
        Check::same([], $overrides->groups, 'groups');
        Check::same(null, $overrides->seed, 'seed');
        Check::same([], $overrides->excludeGroups, 'excludeGroups');
        Check::same([], $overrides->excludeClasses, 'excludeClasses');
        Check::same([], $overrides->excludeMethods, 'excludeMethods');
        Check::same([], $overrides->excludePaths, 'excludePaths');
        Check::same(null, $overrides->repeat, 'repeat');
        Check::same(false, $overrides->repeatUntilFailure, 'repeatUntilFailure');
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

        Check::same(['slow', 'io'], $overrides->excludeGroups, 'excludeGroups');
        Check::same(['Alpha*'], $overrides->excludeClasses, 'excludeClasses');
        Check::same(['two', 'craw?s'], $overrides->excludeMethods, 'excludeMethods');
        Check::same(['tests/Legacy'], $overrides->excludePaths, 'excludePaths');
    }

    #[Test]
    public function extractsRepeatOptions(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments('run', ['repeat' => ['3']]));

        Check::same(3, $overrides->repeat, 'repeat');
        Check::same(false, $overrides->repeatUntilFailure, 'repeatUntilFailure');

        $overrides = CliOverrides::fromArguments(new ParsedArguments('run', ['repeat-until-failure' => [null]]));

        Check::same(null, $overrides->repeat, 'repeat without a count');
        Check::same(true, $overrides->repeatUntilFailure, 'repeatUntilFailure flag');
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

        Check::same(4, $overrides->workers?->fixed, 'workers');
        Check::same(3, $overrides->stopAfterFailures, 'stopAfterFailures');
        Check::same(['slow', 'io'], $overrides->groups, 'groups');
        Check::same(0, $overrides->seed, 'seed zero is a valid seed');
    }

    #[Test]
    public function bailWithoutAValueMeansStopAfterTheFirstFailure(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments(null, ['bail' => [null]]));

        Check::same(1, $overrides->stopAfterFailures, 'stopAfterFailures');
    }

    #[Test]
    public function workersAutoIsKeptAsTheAutoMarker(): void
    {
        $overrides = CliOverrides::fromArguments(new ParsedArguments(null, ['workers' => ['auto']]));

        Check::true($overrides->workers instanceof WorkerCount && $overrides->workers->isAuto(), 'workers to be the auto marker');
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

        foreach ($unusable as $what => $options) {
            Check::throws(
                static function () use ($options): void {
                    CliOverrides::fromArguments(new ParsedArguments(null, $options));
                },
                CliError::class,
                $what,
            );
        }
    }
}
