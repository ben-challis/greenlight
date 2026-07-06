<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\CliError;
use Greenlight\Cli\CliOverrides;
use Greenlight\Cli\ParsedArguments;
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

        Check::true($overrides->workers instanceof \Greenlight\Config\WorkerCount && $overrides->workers->isAuto(), 'workers to be the auto marker');
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
