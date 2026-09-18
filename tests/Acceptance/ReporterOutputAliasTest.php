<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

use function Greenlight\expect;

final readonly class ReporterOutputAliasTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    #[DataRow(['./reports/report.txt', false], label: 'dot segment')]
    #[DataRow(['reports//report.txt', false], label: 'repeated separator')]
    #[DataRow(['{root}/reports/report.txt', false], label: 'absolute path')]
    #[DataRow(['./reports/report.txt', true], label: 'existing output')]
    public function aliasesAreRejectedBeforeOutputFilesChange(string $alias, bool $exists): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'reporter-alias');

        if ($exists) {
            $project->writeFile('reports/report.txt', 'Existing report.');
        }

        $this->expectDuplicate($project, 'reports/report.txt', \str_replace('{root}', $project->directory, $alias));

        if ($exists) {
            expect(\file_get_contents($project->path('reports/report.txt')))->toBe('Existing report.');
        } else {
            expect(\is_dir($project->path('reports')))->toBeFalse();
        }
    }

    #[Test]
    public function aSymbolicLinkParentCannotHideADuplicateNewOutput(): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'reporter-parent-link');
        $project->writeFile('actual/.keep', '');
        \symlink($project->path('actual'), $project->path('linked'));

        $this->expectDuplicate($project, 'actual/reports/report.txt', 'linked/reports/report.txt');

        expect(\is_dir($project->path('actual/reports')))->toBeFalse();
    }

    #[Test]
    public function aSymbolicLinkToAnExistingOutputDoesNotTruncateIt(): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'reporter-file-link');
        $project->writeFile('report.txt', 'Existing report.');
        \symlink($project->path('report.txt'), $project->path('linked.txt'));

        $this->expectDuplicate($project, 'report.txt', 'linked.txt');

        expect(\file_get_contents($project->path('report.txt')))->toBe('Existing report.');
    }

    #[Test]
    public function existingParentSegmentsCannotHideADuplicateOutput(): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'reporter-parent-segment');
        $project->writeFile('reports/sub/.keep', '');
        $project->writeFile('reports/report.txt', 'Existing report.');

        $this->expectDuplicate($project, 'reports/report.txt', 'reports/sub/../report.txt');

        expect(\file_get_contents($project->path('reports/report.txt')))->toBe('Existing report.');
    }

    #[Test]
    public function parentSegmentsResolveAfterSymbolicLinks(): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'reporter-distinct-link');
        $project->writeFile('actual/nested/.keep', '');
        \symlink($project->path('actual/nested'), $project->path('linked'));

        $result = GreenlightCli::run($project->directory, [
            'run',
            '--reporter=jsonl=report.txt',
            '--reporter=plain=linked/../report.txt',
        ]);

        expect($result->exitCode)->toBe(0);
        expect(\file_get_contents($project->path('report.txt')))->toStartWith('{');
        expect(\file_get_contents($project->path('actual/report.txt')))->toContain('1 test, 1 passed');
    }

    private function expectDuplicate(AcceptanceProject $project, string $first, string $second): void
    {
        $result = GreenlightCli::run($project->directory, [
            'run',
            '--reporter=jsonl=' . $first,
            '--reporter=plain=' . $second,
        ]);

        expect($result->exitCode)->toBe(64);
        expect($result->stdout)->toBe('');
        expect($result->stderr)->toBe(
            \sprintf('greenlight: Write reporter output to file "%s" only once.', $second),
        );
    }
}
