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
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'unknown-reporter');
        $result = GreenlightCli::run($project->directory, ['run', '--no-ansi', '--reporter=unknown']);

        Expect::value($result->exitCode)
            ->because('an unknown reporter is a usage error')
            ->toBe(64);
        Expect::value($result->stderr)
            ->because('the error identifies every supported reporter')
            ->toBe(
                'greenlight: Unknown reporter "unknown". Select one of: tty, plain, junit, jsonl, github, teamcity.',
            );
        Expect::value($result->stdout)
            ->because('the test run does not start')
            ->toBe('');
    }

    #[Test]
    public function explicitTtyReporterRunsWithoutAnInteractiveTerminal(): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'tty-reporter');
        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--workers=1', '--no-ansi', '--reporter=tty'],
        );

        Expect::value($result->exitCode)
            ->because('an explicitly selected TTY reporter MUST run without a terminal')
            ->toBe(0);
        Expect::value($result->stdout)
            ->toContain('1 test, 1 passed')
            ->not()
            ->toContain("\x1b[");
        Expect::value($result->stderr)
            ->toBe('');
    }

    #[Test]
    public function reportersCanWriteToSeparateStandardAndFileOutputs(): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'reporter-file-output');
        $junit = $project->path('reports/junit.xml');
        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--workers=1', '--no-ansi', '--reporter=plain', '--reporter=junit=reports/junit.xml'],
        );

        Expect::value($result->exitCode)->toBe(0);
        Expect::value($result->stdout)
            ->because('the reporter without a file MUST continue to use standard output')
            ->toContain('1 test, 1 passed')
            ->not()
            ->toContain('<?xml');
        Expect::value((string) \file_get_contents($junit))
            ->because('the reporter file path MUST resolve from the command working directory')
            ->toStartWith("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n")
            ->toContain('<testsuites name="greenlight" tests="1"');
        Expect::value($result->stderr)->toBe('');
    }

    #[Test]
    public function aFileTtyReporterUsesAppendOnlyOutput(): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'file-tty-reporter');
        $report = $project->path('reports/tty.txt');
        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--workers=1', '--reporter=tty=reports/tty.txt'],
        );

        Expect::value($result->exitCode)->toBe(0);
        Expect::value($result->stdout)->toBe('');
        Expect::value((string) \file_get_contents($report))
            ->because('a file is not an interactive terminal')
            ->toContain('1 test, 1 passed')
            ->not()
            ->toContain("\x1b[");
        Expect::value($result->stderr)->toBe('');
    }

    #[Test]
    public function anEmptyReporterFileIsAUsageError(): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'empty-reporter-file');
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=junit=']);

        Expect::value($result->exitCode)->toBe(64);
        Expect::value($result->stdout)->toBe('');
        Expect::value($result->stderr)
            ->toBe('greenlight: --reporter requires <name> or <name>=<path>. Received "junit=".');
    }

    #[Test]
    public function anUnknownReporterDoesNotCreateItsOutputDirectory(): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'unknown-file-reporter');
        $directory = $project->path('reports');
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=unknown=reports/output.txt']);

        Expect::value($result->exitCode)->toBe(64);
        Expect::value($result->stdout)->toBe('');
        Expect::value($result->stderr)
            ->toContain('greenlight: Unknown reporter "unknown".');
        Expect::value(\is_dir($directory))
            ->because('Greenlight MUST validate reporter names before it changes the file system')
            ->toBeFalse();
    }

    #[Test]
    public function reportersCannotShareOneFile(): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'duplicate-reporter-file');
        $result = GreenlightCli::run($project->directory, [
            'run',
            '--reporter=plain=report.txt',
            '--reporter=junit=report.txt',
        ]);

        Expect::value($result->exitCode)->toBe(64);
        Expect::value($result->stdout)->toBe('');
        Expect::value($result->stderr)
            ->toBe('greenlight: Write reporter output to file "report.txt" only once.');
        Expect::value(\file_exists($project->path('report.txt')))
            ->because('Greenlight MUST validate reporter targets before it creates them')
            ->toBeFalse();
    }

    #[Test]
    public function anUnavailableReporterFileStopsBeforeTheTestRun(): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'unavailable-reporter-file');
        $project->writeFile('blocked', 'not a directory');
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=junit=blocked/junit.xml']);

        Expect::value($result->exitCode)->toBe(1);
        Expect::value($result->stdout)->toBe('');
        Expect::value($result->stderr)
            ->toContain('greenlight: Greenlight could not create reporter output directory "')
            ->toContain('/blocked":');
    }
}
