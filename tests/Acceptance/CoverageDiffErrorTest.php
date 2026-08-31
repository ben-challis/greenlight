<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\CoverageJson;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class CoverageDiffErrorTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    #[DataSet('missingExportLabels')]
    public function missingCoverageExportsNameTheirRole(string $missingLabel): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'coverage-diff-missing-' . $missingLabel);
        $presentLabel = $missingLabel === 'baseline' ? 'current' : 'baseline';
        $project->writeFile($presentLabel . '.json', '{"v":1,"files":{}}');

        $result = GreenlightCli::run($project->directory, [
            'coverage:diff',
            '--baseline=baseline.json',
            '--current=current.json',
        ]);

        Expect::that($result->exitCode)
            ->because('missing coverage exports name their role')
            ->toBe(1);
        Expect::that($result->output())
            ->toContain(\sprintf(
                'Greenlight could not read the %s coverage export at "%s.json"',
                $missingLabel,
                $missingLabel,
            ));
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function missingExportLabels(): iterable
    {
        yield 'baseline' => ['baseline'];
        yield 'current' => ['current'];
    }

    #[Test]
    #[DataSet('invalidExportLabels')]
    public function malformedCoverageExportsNameTheirRole(string $invalidLabel): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'coverage-diff-error-' . $invalidLabel);
        $valid = '{"v":1,"files":{}}';
        $invalid = '{"v":1,"files":"invalid"}';

        foreach (['baseline', 'current'] as $label) {
            $project->writeFile($label . '.json', $label === $invalidLabel ? $invalid : $valid);
        }

        $result = GreenlightCli::run($project->directory, [
            'coverage:diff',
            '--baseline=baseline.json',
            '--current=current.json',
        ]);

        Expect::that($result->exitCode)
            ->because('malformed coverage exports name their role')
            ->toBe(1);
        Expect::that($result->output())
            ->toContain(\sprintf(
                'The %s file is not a valid coverage export: '
                . 'Coverage JSON document is invalid: use an object for "files".',
                $invalidLabel,
            ));
    }

    #[Test]
    #[DataSet('invalidExportLabels')]
    public function emptyCoverageExportsAreMalformedNotUnreadable(string $invalidLabel): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'coverage-diff-empty-' . $invalidLabel);
        $valid = '{"v":1,"files":{}}';

        foreach (['baseline', 'current'] as $label) {
            $project->writeFile($label . '.json', $label === $invalidLabel ? '' : $valid);
        }

        $result = GreenlightCli::run($project->directory, [
            'coverage:diff',
            '--baseline=baseline.json',
            '--current=current.json',
        ]);

        Expect::that($result->exitCode)
            ->because('empty readable exports are malformed instead of unreadable')
            ->toBe(1);
        Expect::that($result->output())
            ->toContain(\sprintf(
                'The %s file is not a valid coverage export: '
                . 'Coverage JSON document is invalid: Syntax error',
                $invalidLabel,
            ))
            ->not()->toContain('could not read');
    }

    #[Test]
    public function projectRootOptionsMustBeUsedTogether(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'coverage-diff-one-root');
        $project->writeFile('baseline.json', '{"v":1,"files":{}}');
        $project->writeFile('current.json', '{"v":1,"files":{}}');

        $result = GreenlightCli::run($project->directory, [
            'coverage:diff',
            '--baseline=baseline.json',
            '--current=current.json',
            '--baseline-root=/old/project',
        ]);

        Expect::that($result->exitCode)
            ->because('one project root cannot define both path mappings')
            ->toBe(64);
        Expect::that($result->output())
            ->toContain('Use --baseline-root=<path> and --current-root=<path> together.');
    }

    #[Test]
    public function projectRootMustContainEveryCoveragePath(): void
    {
        $directory = $this->tempDirectory->subdirectory('coverage-diff-outside-root');
        CoverageJson::write(
            $directory . '/baseline.json',
            new CoverageMap([
                new FileCoverage('/dependency/A.php', [1], []),
            ]),
        );
        CoverageJson::write(
            $directory . '/current.json',
            new CoverageMap(),
        );

        $result = GreenlightCli::run($directory, [
            'coverage:diff',
            '--baseline=baseline.json',
            '--current=current.json',
            '--baseline-root=/project',
            '--current-root=/project',
        ]);

        Expect::that($result->exitCode)
            ->because('partial root normalization MUST fail')
            ->toBe(1);
        Expect::that($result->output())
            ->toContain('Coverage path "/dependency/A.php" is not below project root "/project".');
    }

    #[Test]
    public function invalidCoverageGateIsAUsageError(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'coverage-diff-invalid-gate');
        $project->writeFile('baseline.json', '{"v":1,"files":{}}');
        $project->writeFile('current.json', '{"v":1,"files":{}}');

        $result = GreenlightCli::run($project->directory, [
            'coverage:diff',
            '--baseline=baseline.json',
            '--current=current.json',
            '--minimum-coverage=100.01',
        ]);

        Expect::that($result->exitCode)
            ->because('coverage:diff MUST validate its coverage-gate options')
            ->toBe(64);
        Expect::that($result->output())
            ->toContain('--minimum-coverage requires a percentage from 0 through 100');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function invalidExportLabels(): iterable
    {
        yield 'baseline' => ['baseline'];
        yield 'current' => ['current'];
    }
}
