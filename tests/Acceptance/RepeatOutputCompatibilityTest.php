<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class RepeatOutputCompatibilityTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    #[DataSet('repeatOptions')]
    public function repeatRejectsJUnitOutput(string $repeatOption): void
    {
        $project = $this->writeProject('repeat-junit');
        $result = GreenlightCli::run($project->directory, [
            'run',
            '--reporter=junit',
            '--workers=1',
            '--no-ansi',
            $repeatOption,
        ]);

        Expect::that($result->exitCode)
            ->because('repeat modes MUST reject a report that describes one run')
            ->toBe(64);
        Expect::that($result->stderr)
            ->toContain('Do not use --repeat or --repeat-until-failure with JUnit output.')
            ->toContain('Run Greenlight separately for each required report.');
        Expect::that($result->stdout)
            ->because('the incompatible JUnit run MUST stop before test execution')
            ->toBe('');
    }

    #[Test]
    public function oneRequestedRunKeepsJUnitOutputAvailable(): void
    {
        $project = $this->writeProject('single-junit');
        $result = GreenlightCli::run($project->directory, [
            'run',
            '--reporter=junit',
            '--workers=1',
            '--no-ansi',
            '--repeat=1',
        ]);

        Expect::that($result->exitCode)
            ->because('--repeat=1 is one run and MUST keep JUnit output available')
            ->toBe(0);
        Expect::that(\substr_count($result->stdout, '<?xml version="1.0" encoding="UTF-8"?>'))
            ->because('one requested run MUST write one JUnit document')
            ->toBe(1);
        Expect::that($result->stderr)->toBe('');
    }

    #[Test]
    public function repeatRejectsAFileJUnitReporterBeforeItCreatesTheFile(): void
    {
        $project = $this->writeProject('repeat-file-junit');
        $report = $project->path('reports/junit.xml');
        $result = GreenlightCli::run($project->directory, [
            'run',
            '--reporter=junit=reports/junit.xml',
            '--workers=1',
            '--no-ansi',
            '--repeat=2',
        ]);

        Expect::that($result->exitCode)->toBe(64);
        Expect::that($result->stderr)
            ->toContain('Do not use --repeat or --repeat-until-failure with JUnit output.');
        Expect::that($result->stdout)->toBe('');
        Expect::that(\file_exists($report))
            ->because('Greenlight MUST validate repeat output before it creates the report file')
            ->toBeFalse();
    }

    #[Test]
    #[DataSet('coverageConfigurations')]
    public function repeatRejectsEnabledCoverage(string $coverageConfiguration, string $repeatOption): void
    {
        $project = $this->writeProject('repeat-coverage');
        $project->writeFile('greenlight.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/ProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->coverage(fn($coverage) => $coverage%s);

            PHP,
            $coverageConfiguration,
        ));

        $result = GreenlightCli::run($project->directory, [
            'run',
            '--reporter=plain',
            '--no-ansi',
            $repeatOption,
        ]);

        Expect::that($result->exitCode)
            ->because('repeat modes MUST reject coverage that describes one run')
            ->toBe(64);
        Expect::that($result->stderr)
            ->toContain('Do not use --repeat or --repeat-until-failure with enabled coverage.')
            ->toContain('Run Greenlight separately for each required report.');
        Expect::that($result->stdout)
            ->because('the incompatible coverage run MUST stop before test execution')
            ->toBe('');
    }

    #[Test]
    public function repeatKeepsAValidJsonlEventStream(): void
    {
        $project = $this->writeProject('repeat-jsonl');
        $result = GreenlightCli::run($project->directory, [
            'run',
            '--reporter=jsonl',
            '--workers=1',
            '--no-ansi',
            '--repeat=2',
        ]);
        $events = [];

        foreach ($result->stdoutLines() as $line) {
            /** @var array{event: string} $envelope */
            $envelope = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            $events[] = $envelope['event'];
        }

        Expect::that($result->exitCode)
            ->because('JSONL supports an event sequence for more than one run')
            ->toBe(0);
        Expect::that(\array_count_values($events)['run-started'] ?? 0)
            ->because('each repeated run MUST start one JSONL event sequence')
            ->toBe(2);
        Expect::that(\array_count_values($events)['run-finished'] ?? 0)
            ->because('each repeated run MUST finish one JSONL event sequence')
            ->toBe(2);
        Expect::that($result->stderr)
            ->because('repeat status MUST not invalidate JSONL on standard output')
            ->toContain('Repeat: 2 iterations, all passed');
    }

    #[Test]
    public function repeatKeepsAValidJsonlFileAndStatusOnStandardOutput(): void
    {
        $project = $this->writeProject('repeat-file-jsonl');
        $report = $project->path('reports/events.jsonl');
        $result = GreenlightCli::run($project->directory, [
            'run',
            '--reporter=jsonl=reports/events.jsonl',
            '--workers=1',
            '--no-ansi',
            '--repeat=2',
        ]);
        $lines = \file($report, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $events = [];

        foreach ($lines === false ? [] : $lines as $line) {
            /** @var array{event: string} $envelope */
            $envelope = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            $events[] = $envelope['event'];
        }

        Expect::that($result->exitCode)->toBe(0);
        Expect::that(\array_count_values($events)['run-started'] ?? 0)->toBe(2);
        Expect::that(\array_count_values($events)['run-finished'] ?? 0)->toBe(2);
        Expect::that($result->stdout)
            ->because('the JSONL file leaves standard output available for repeat status')
            ->toContain('Repeat: 2 iterations, all passed');
        Expect::that($result->stderr)->toBe('');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function repeatOptions(): iterable
    {
        yield 'fixed repeat count' => ['--repeat=2'];
        yield 'repeat until failure' => ['--repeat-until-failure'];
    }

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function coverageConfigurations(): iterable
    {
        yield 'fixed repeat with collection without exports' => ['', '--repeat=2'];
        yield 'repeat until failure with collection without exports' => ['', '--repeat-until-failure'];
        yield 'fixed repeat with a coverage export' => ["->export('json', 'coverage.json')", '--repeat=2'];
        yield 'repeat until failure with a coverage export' => ["->export('json', 'coverage.json')", '--repeat-until-failure'];
    }

    private function writeProject(string $name): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, $name);
        $project->writeFile('tests/ProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace RepeatOutputCompatibilityProbe;

            use Greenlight\Attribute\Test;

            final class ProbeTest
            {
                #[Test]
                public function passes(): void {}
            }
            PHP);
        $project->configureWithTestFiles(['tests/ProbeTest.php']);

        return $project;
    }
}
