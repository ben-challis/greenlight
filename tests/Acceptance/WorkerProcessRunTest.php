<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

final readonly class WorkerProcessRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function workerCountsProduceTheSameResults(): void
    {
        $project = AcceptanceProject::createWithTwoPassingTests($this->tempDirectory, 'parallel');
        $oneWorker = GreenlightCli::run($project->directory, ['run', '--workers=1']);
        $threeWorkers = GreenlightCli::run($project->directory, ['run', '--workers=3']);
        Expect::that($oneWorker->exitCode)->because('worker counts produce the same results')->toBe(0);
        Expect::that($threeWorkers->exitCode)->toBe(0);
        Expect::that($this->summaryLine($oneWorker->output()))->toBe('2 tests, 2 passed, 0 expectations');
        Expect::that($this->summaryLine($threeWorkers->output()))->toBe('2 tests, 2 passed, 0 expectations');
    }

    #[Test]
    public function configuredPluginsReachWorkersAcrossTheProcessBoundary(): void
    {
        $result = $this->runIn('PluginRunConfig', ['run', '--workers=2']);

        Expect::that($result->exitCode)->because('configured plugins reach workers across the process boundary')->toBe(0);
        Expect::that($this->summaryLine($result->output()))->toBe('2 tests, 1 passed, 1 skipped, 0 expectations');
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataSet('workerDisposalModes')]
    public function workerDisposalFailuresAreEquivalentAcrossWorkerCounts(array $arguments): void
    {
        $result = $this->runIn('HarnessDisposalRun', $arguments);

        Expect::that($result->exitCode)
            ->because('worker disposal MUST make each worker count unsuccessful')
            ->toBe(1);
        Expect::that($result->output())
            ->because('each worker count MUST report the worker disposal failure')
            ->toContain('test broke first')
            ->toContain('Worker harness service disposal failed.')
            ->toContain('harness service disposal broke');
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function workerDisposalModes(): iterable
    {
        yield 'one worker' => [['run', '--workers=1']];
        yield 'parallel drain' => [['run', '--workers=2']];
    }

    /**
     * @param list<string> $arguments
     */
    private function runIn(string $fixtureConfigDir, array $arguments): ProcessResult
    {
        return GreenlightCli::run(FixturePath::get($fixtureConfigDir), $arguments);
    }

    private function summaryLine(string $output): string
    {
        if (\preg_match('/^\d+ tests?, \d+ passed(?:, \d+ failed)?(?:, \d+ errored)?(?:, \d+ skipped)?, \d+ expectations?$/m', $output, $matches) !== 1) {
            Fail::because("No summary line found in output:\n" . $output);
        }

        return $matches[0];
    }
}
