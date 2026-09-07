<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Event\RunFinished;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;
use Greenlight\Event\WireEvent;
use Greenlight\Event\WorkerSpawned;
use Greenlight\Event\WorkerTiming;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Event\EventCodec;
use Greenlight\Result\ResultSummary;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

final readonly class ProfileReportWireValuesTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    /** @param non-empty-string $workerId */
    #[Test]
    #[DataSet('numericWorkerIds')]
    public function savedProfilesRetainNumericWorkerIds(string $workerId): void
    {
        $report = $this->report([
            new RunStarted('run-1', 1, 1, 1.0),
            new WorkerSpawned($workerId, 11, 1.0),
            new TestClassStarted('Acme\\ExampleTest', 1.0, $workerId),
            new TestClassFinished('Acme\\ExampleTest', 2.0, $workerId),
            new RunFinished('run-1', new ResultSummary(passed: 1), 1.0, 2.0),
        ]);

        Expect::that($report->exitCode)->because('Profile command output: ' . $report->output())->toBe(0);
        Expect::that($report->stdout)
            ->toContain('Workers: 1 requested, 1 spawned')
            ->toContain("\n  " . $workerId . ' ')
            ->toContain('1.000s  100%');
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function numericWorkerIds(): iterable
    {
        yield 'zero' => ['0'];
        yield 'positive integer' => ['1'];
        yield 'negative integer' => ['-1'];
        yield 'leading zero' => ['01'];
    }

    #[Test]
    public function savedProfilesSaturateAssignmentGapTotals(): void
    {
        $report = $this->report([
            new RunStarted('run-1', 0, 2, 1.0),
            new RunFinished('run-1', new ResultSummary(), 0.0, 1.0, [
                new WorkerTiming('w-1', null, null, null, \PHP_INT_MAX, 0.0, 0.0, 0.0, 0.0, null),
                new WorkerTiming('w-2', null, null, null, 1, 0.0, 0.0, 0.0, 0.0, null),
            ]),
        ]);

        Expect::that($report->exitCode)->because('Profile command output: ' . $report->output())->toBe(0);
        Expect::that($report->stdout)->toContain(\sprintf(
            'Assignment gaps: 0.000s total (%d gaps)',
            \PHP_INT_MAX,
        ));
    }

    /** @param list<WireEvent> $events */
    private function report(array $events): ProcessResult
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'profile-wire-values');
        $project->writeFile('profile.jsonl', \implode('', \array_map(EventCodec::encodeJsonLine(...), $events)));

        return GreenlightCli::run($project->directory, ['profile:report', '--input=profile.jsonl', '--no-ansi']);
    }
}
