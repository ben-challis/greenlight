<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class ReporterSelectionTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function unknownReporterFailsBeforeTheTestRunStarts(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'unknown-reporter');
        $result = GreenlightCli::run($project->directory, ['run', '--no-ansi', '--reporter=unknown']);

        Expect::that($result->exitCode)
            ->because('an unknown reporter is a usage error')
            ->toBe(64);
        Expect::that($result->stderr)
            ->because('the error identifies every supported reporter')
            ->toBe(
                'greenlight: Unknown reporter "unknown". Select tty, plain, junit, jsonl, github, or teamcity.',
            );
        Expect::that($result->stdout)
            ->because('the test run does not start')
            ->toBe('');
    }
}
