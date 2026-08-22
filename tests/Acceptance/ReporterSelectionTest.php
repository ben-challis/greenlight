<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class ReporterSelectionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

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
                'greenlight: Unknown reporter "unknown". Select one of: tty, plain, junit, jsonl, github, teamcity.',
            );
        Expect::that($result->stdout)
            ->because('the test run does not start')
            ->toBe('');
    }

    #[Test]
    public function explicitTtyReporterRunsWithoutAnInteractiveTerminal(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'tty-reporter');
        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--workers=1', '--no-ansi', '--reporter=tty'],
        );

        Expect::that($result->exitCode)
            ->because('an explicitly selected TTY reporter MUST run without a terminal')
            ->toBe(0);
        Expect::that($result->stdout)
            ->toContain('7 tests, 7 passed')
            ->not()
            ->toContain("\x1b[");
        Expect::that($result->stderr)
            ->toBe('');
    }

    #[Test]
    public function reportersCanWriteToSeparateStandardAndFileOutputs(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'reporter-file-output');
        $junit = $project->path('reports/junit.xml');
        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--workers=1', '--no-ansi', '--reporter=plain', '--reporter=junit=reports/junit.xml'],
        );

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->stdout)
            ->because('the reporter without a file MUST continue to use standard output')
            ->toContain('7 tests, 7 passed')
            ->not()
            ->toContain('<?xml');
        Expect::that((string) \file_get_contents($junit))
            ->because('the reporter file path MUST resolve from the command working directory')
            ->toStartWith("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n")
            ->toContain('<testsuites name="greenlight" tests="7"');
        Expect::that($result->stderr)->toBe('');
    }

    #[Test]
    public function aFileTtyReporterUsesAppendOnlyOutput(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'file-tty-reporter');
        $report = $project->path('reports/tty.txt');
        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--workers=1', '--reporter=tty=reports/tty.txt'],
        );

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->stdout)->toBe('');
        Expect::that((string) \file_get_contents($report))
            ->because('a file is not an interactive terminal')
            ->toContain('7 tests, 7 passed')
            ->not()
            ->toContain("\x1b[");
        Expect::that($result->stderr)->toBe('');
    }

    #[Test]
    public function anEmptyReporterFileIsAUsageError(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'empty-reporter-file');
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=junit=']);

        Expect::that($result->exitCode)->toBe(64);
        Expect::that($result->stdout)->toBe('');
        Expect::that($result->stderr)
            ->toBe('greenlight: --reporter requires <name> or <name>=<path>. Received "junit=".');
    }

    #[Test]
    public function anUnknownReporterDoesNotCreateItsOutputDirectory(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'unknown-file-reporter');
        $directory = $project->path('reports');
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=unknown=reports/output.txt']);

        Expect::that($result->exitCode)->toBe(64);
        Expect::that($result->stdout)->toBe('');
        Expect::that($result->stderr)
            ->toContain('greenlight: Unknown reporter "unknown".');
        Expect::that(\is_dir($directory))
            ->because('Greenlight MUST validate reporter names before it changes the file system')
            ->toBeFalse();
    }

    #[Test]
    public function reportersCannotShareOneFile(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'duplicate-reporter-file');
        $result = GreenlightCli::run($project->directory, [
            'run',
            '--reporter=plain=report.txt',
            '--reporter=junit=report.txt',
        ]);

        Expect::that($result->exitCode)->toBe(64);
        Expect::that($result->stdout)->toBe('');
        Expect::that($result->stderr)
            ->toBe('greenlight: Write reporter output to file "report.txt" only once.');
        Expect::that(\file_exists($project->path('report.txt')))
            ->because('Greenlight MUST validate reporter targets before it creates them')
            ->toBeFalse();
    }

    #[Test]
    public function anUnavailableReporterFileStopsBeforeTheTestRun(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'unavailable-reporter-file');
        $project->writeFile('blocked', 'not a directory');
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=junit=blocked/junit.xml']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->stdout)->toBe('');
        Expect::that($result->stderr)
            ->toContain('greenlight: Greenlight could not create reporter output directory "')
            ->toContain('/blocked":');
    }
}
